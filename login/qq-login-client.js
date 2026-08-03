// qq-login-client.js - 简化版，移除复杂依赖
const crypto = require('crypto');

const XUIBASE = 'https://xui.ptlogin2.qq.com';
const GRAPH_BASE = 'https://graph.qq.com';
const GAME_SITE = 'https://dld.qzapp.z.qq.com';

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';
const REFERER = 'https://xui.ptlogin2.qq.com/';

function hash33(s) {
  let h = 0;
  for (let i = 0; i < s.length; i++) h += (h << 5) + s.charCodeAt(i);
  return h & 0x7fffffff;
}

function parseSetCookie(res) {
  const cookies = {};
  const setCookieHeaders = res.headers.getSetCookie?.() ?? [];
  for (const header of setCookieHeaders) {
    const parts = header.split(';');
    const [name, value] = parts[0].split('=').map(s => s.trim());
    const isDelete = parts.some(p => p.includes('1970') || p.includes('expires=Thu, 01 Jan 1970'));
    if (!isDelete && value && !value.includes('deleted')) {
      cookies[name] = value;
    }
  }
  return cookies;
}

function cookieStr(jar) {
  return Array.from(jar.entries())
    .filter(([k, v]) => v && v !== '')
    .map(([k, v]) => `${k}=${v}`)
    .join('; ');
}

function merge(jar, incoming) {
  for (const [k, v] of Object.entries(incoming)) {
    if (v && v !== '') jar.set(k, v);
  }
}

class QqLoginClient {
  async initSession() {
    const jar = new Map();
    const url = `${XUIBASE}/cgi-bin/xlogin?appid=716027609&daid=383&style=33&login_text=登录&hide_title_bar=1&hide_border=1&target=self&s_url=${encodeURIComponent(GRAPH_BASE+'/oauth2.0/login_jump')}&pt_3rd_aid=102067279`;
    const res = await fetch(url, { 
      headers: { 'User-Agent': UA },
      redirect: 'manual'
    });
    merge(jar, parseSetCookie(res));
    return jar;
  }

  async getQrCode(jar) {
    const url = `${XUIBASE}/ssl/ptqrshow?appid=716027609&e=2&l=M&s=3&d=72&v=4&t=${Math.random()}&daid=383&pt_3rd_aid=102067279&u1=${encodeURIComponent(GRAPH_BASE+'/oauth2.0/login_jump')}`;
    const res = await fetch(url, {
      headers: { 
        'User-Agent': UA, 
        Referer: REFERER, 
        Cookie: cookieStr(jar) 
      },
    });
    if (!res.ok) throw new Error(`ptqrshow: ${res.status}`);
    merge(jar, parseSetCookie(res));
    const qrsig = jar.get('qrsig');
    if (!qrsig) throw new Error('qrsig not found');
    const buf = Buffer.from(await res.arrayBuffer());
    return { qrImage: `data:image/png;base64,${buf.toString('base64')}`, qrsig };
  }

  async checkStatus(jar, ptqrtoken) {
    const url = `${XUIBASE}/ssl/ptqrlogin?u1=${encodeURIComponent(GRAPH_BASE+'/oauth2.0/login_jump')}&ptqrtoken=${ptqrtoken}&ptredirect=0&h=1&t=1&g=1&from_ui=1&ptlang=2052&action=0-0-${Date.now()}&js_ver=26030415&js_type=1&login_sig=${encodeURIComponent(jar.get('pt_login_sig')||'')}&pt_uistyle=40&aid=716027609&daid=383&pt_3rd_aid=102067279`;
    const res = await fetch(url, {
      headers: { 
        'User-Agent': UA, 
        Referer: REFERER, 
        Cookie: cookieStr(jar) 
      },
      redirect: 'manual'
    });
    if (!res.ok) throw new Error(`ptqrlogin: ${res.status}`);
    merge(jar, parseSetCookie(res));
    return this.parsePtuiCB(await res.text());
  }

  async completeLogin(callbackUrl, jar, nickname) {
    console.error('📌 completeLogin - 开始完成登录...');
    console.error('  callbackUrl:', callbackUrl);
    
    let url = callbackUrl;
    let finalJar = new Map(jar);
    
    for (let i = 0; i < 5; i++) {
      const res = await fetch(url, {
        headers: { 
          'User-Agent': UA, 
          Cookie: cookieStr(finalJar) 
        },
        redirect: 'manual',
      });
      merge(finalJar, parseSetCookie(res));
      console.error(`  重定向 ${i+1}: status=${res.status}, location=${res.headers.get('location') || '无'}`);
      
      if (res.status < 300 || res.status >= 400 || !res.headers.get('location')) break;
      url = new URL(res.headers.get('location'), url).href;
    }

    // 获取 OAuth code
    const pSkey = finalJar.get('p_skey') || '';
    let gTk = 5381;
    for (let i = 0; i < pSkey.length; i++) gTk += (gTk << 5) + pSkey.charCodeAt(i);
    gTk &= 0x7fffffff;

    const body = new URLSearchParams({
      response_type: 'code',
      client_id: '102067279',
      redirect_uri: 'https://dld.qzapp.z.qq.com/index.php',
      scope: 'all',
      from_ptlogin: '1',
      src: '1',
      update_auth: '0',
      openapi: '#',
      g_tk: String(gTk || '0'),
      auth_time: String(Date.now()),
      ui: finalJar.get('ui') || '',
    }).toString();

    const authRes = await fetch(`${GRAPH_BASE}/oauth2.0/authorize`, {
      method: 'POST',
      headers: {
        'User-Agent': UA,
        'Content-Type': 'application/x-www-form-urlencoded',
        Referer: `${GRAPH_BASE}/oauth2.0/show?which=Login&display=pc&response_type=code&client_id=102067279&redirect_uri=${encodeURIComponent('https://dld.qzapp.z.qq.com/index.php')}&scope=all`,
        Cookie: cookieStr(finalJar),
      },
      body,
      redirect: 'manual',
    });
    merge(finalJar, parseSetCookie(authRes));

    let code = null;
    if (authRes.status >= 300 && authRes.status < 400) {
      const loc = authRes.headers.get('location');
      if (loc) {
        const match = loc.match(/[?&]code=([^&]+)/);
        if (match) code = match[1];
      }
    }
    if (!code) {
      const bodyText = await authRes.text();
      const match = bodyText.match(/code=([^&"'\s]+)/);
      if (match) code = match[1];
    }
    if (!code) throw new Error('OAuth code not obtained');
    
    console.error('✅ 获取到 OAuth code:', code);

    // 获取游戏 Cookie
    const gameUrl = `${GAME_SITE}/index.php?code=${code}`;
    let current = gameUrl;
    
    for (let i = 0; i < 5; i++) {
      const res = await fetch(current, {
        headers: { 
          'User-Agent': UA, 
          Cookie: cookieStr(finalJar) 
        },
        redirect: 'manual',
      });
      merge(finalJar, parseSetCookie(res));
      if (res.status < 300 || res.status >= 400 || !res.headers.get('location')) {
        if (res.status === 200) {
          merge(finalJar, parseSetCookie(res));
        }
        break;
      }
      current = new URL(res.headers.get('location'), current).href;
    }

    const gameCookies = {
      openId: finalJar.get('openId') || finalJar.get('openid') || '',
      accessToken: finalJar.get('accessToken') || finalJar.get('accesstoken') || finalJar.get('access_token') || '',
      newuin: finalJar.get('newuin') || finalJar.get('uin') || ''
    };
    
    if (!gameCookies.openId) {
      const pUin = finalJar.get('p_uin') || '';
      if (pUin) gameCookies.openId = pUin.replace(/^o/, '');
    }
    
    const uin = (finalJar.get('uin') || finalJar.get('newuin') || '').replace(/^o/, '');
    
    console.error('📌 最终提取的游戏 Cookie:');
    console.error('  openId:', gameCookies.openId);
    console.error('  accessToken:', gameCookies.accessToken);
    console.error('  newuin:', gameCookies.newuin);

    return {
      gameCookies,
      uin,
      nickname: nickname || finalJar.get('nickname') || '',
    };
  }

  parsePtuiCB(body) {
    const match = body.match(/ptuiCB\('(\d+)'/);
    if (!match) return { code: 0 };
    const code = parseInt(match[1]);
    if (code !== 0) return { code };
    const args = body.match(/ptuiCB\((.+)\)/)?.[1] || '';
    const parts = [];
    let cur = '', inQ = false;
    for (const ch of args) {
      if (ch === "'") { inQ = !inQ; continue; }
      if (ch === ',' && !inQ) { parts.push(cur.trim()); cur = ''; continue; }
      cur += ch;
    }
    if (cur) parts.push(cur.trim());
    return { 
      code, 
      callbackUrl: parts[2] || '', 
      nickname: parts[5] || '' 
    };
  }

  hash33(s) { return hash33(s); }
}

module.exports = { QqLoginClient, hash33 };