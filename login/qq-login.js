// qq-login.js - 只保存 YAML，通过 newuin 判断，存在覆盖，不存在追加
const { QqLoginClient } = require('./qq-login-client');
const fs = require('fs');

const YAML_FILE = __dirname + '/qq_cookies.yaml';
const client = new QqLoginClient();
let jar = new Map();
let globalState = {};

// 读取现有 YAML 中的账号列表
function loadExistingYaml() {
  if (!fs.existsSync(YAML_FILE)) {
    return { accounts: [], exists: false };
  }
  
  try {
    const content = fs.readFileSync(YAML_FILE, 'utf8');
    const accounts = [];
    let currentAccount = null;
    const lines = content.split('\n');
    
    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed === 'DALEDOU_COOKIES:') continue;
      if (trimmed === '' || trimmed.startsWith('#')) continue;
      
      if (trimmed.startsWith('- openId:')) {
        if (currentAccount) accounts.push(currentAccount);
        currentAccount = {};
        const match = trimmed.match(/openId:\s*"([^"]+)"/);
        if (match) currentAccount.openId = match[1];
      } else if (trimmed.startsWith('newuin:') && currentAccount) {
        const match = trimmed.match(/newuin:\s*"([^"]+)"/);
        if (match) currentAccount.newuin = match[1];
      } else if (trimmed.startsWith('accessToken:') && currentAccount) {
        const match = trimmed.match(/accessToken:\s*"([^"]+)"/);
        if (match) currentAccount.accessToken = match[1];
      }
    }
    if (currentAccount) accounts.push(currentAccount);
    
    return { accounts, exists: true };
  } catch (e) {
    console.error('⚠️ 读取 YAML 失败:', e.message);
    return { accounts: [], exists: false };
  }
}

// 只保存 YAML（通过 newuin 判断，存在覆盖，不存在追加）
function save(data) {
  // 读取现有 YAML 账号
  const { accounts } = loadExistingYaml();
  
  // 新账号信息
  const newAccount = {
    openId: data.gameCookies.openId,
    newuin: data.gameCookies.newuin,
    accessToken: data.gameCookies.accessToken
  };
  
  // 查找是否已存在（通过 newuin）
  let found = false;
  for (let i = 0; i < accounts.length; i++) {
    if (accounts[i].newuin === newAccount.newuin) {
      // 存在则覆盖
      accounts[i] = newAccount;
      found = true;
      console.error('✅ 更新已有账号 (newuin: ' + newAccount.newuin + ')');
      break;
    }
  }
  
  // 不存在则追加
  if (!found) {
    accounts.push(newAccount);
    console.error('✅ 追加新账号 (newuin: ' + newAccount.newuin + ')');
  }
  
  // 生成 YAML
  let yamlContent = 'DALEDOU_COOKIES:\n';
  for (let i = 0; i < accounts.length; i++) {
    const acc = accounts[i];
    let comment = '账号' + (i + 1);
    // 尝试从原文件读取对应账号的注释
    if (fs.existsSync(YAML_FILE)) {
      const originalContent = fs.readFileSync(YAML_FILE, 'utf8');
      const lines = originalContent.split('\n');
      let commentIdx = 0;
      for (const line of lines) {
        if (line.trim().startsWith('#') && commentIdx === i) {
          comment = line.trim().replace(/^#\s*/, '');
          break;
        }
        if (line.trim().startsWith('- openId:')) {
          commentIdx++;
        }
      }
    }
    yamlContent += `  # ${comment}\n`;
    yamlContent += `  - openId: "${acc.openId}"\n`;
    yamlContent += `    newuin: "${acc.newuin}"\n`;
    yamlContent += `    accessToken: "${acc.accessToken}"\n`;
  }
  
  fs.writeFileSync(YAML_FILE, yamlContent);
  console.error('✅ 已保存 YAML:', YAML_FILE);
}

function out(data) { console.log('RESULT:' + JSON.stringify(data)); }

async function start() {
  try {
    console.error('📌 初始化 Session...');
    jar = await client.initSession();

    console.error('📌 获取二维码...');
    const { qrImage, qrsig } = await client.getQrCode(jar);
    
    fs.writeFileSync('qrcode.png', Buffer.from(qrImage.split(',')[1], 'base64'));
    
    const jarData = {};
    for (const [k, v] of jar.entries()) jarData[k] = v;
    fs.writeFileSync(__dirname + '/.jar.tmp', JSON.stringify(jarData));
    fs.writeFileSync(__dirname + '/.qrsig.tmp', qrsig);
    
    console.error('📱 二维码已生成');
    out({ success: true, qrImage, qrsig });
  } catch (e) {
    out({ success: false, error: e.message });
  }
}

async function poll() {
  try {
    // 从命令行参数获取 qrsig
    let qrsig = null;
    const args = process.argv.slice(2);
    for (let i = 0; i < args.length; i++) {
      if (args[i] === 'qrsig' && i + 1 < args.length) {
        qrsig = args[i + 1];
        break;
      }
    }
    
    // 如果没传参，从临时文件读取
    if (!qrsig && fs.existsSync(__dirname + '/.qrsig.tmp')) {
      qrsig = fs.readFileSync(__dirname + '/.qrsig.tmp', 'utf8').trim();
    }
    
    if (!qrsig) {
      return out({ success: false, error: '缺少 qrsig 参数' });
    }
    
    // 恢复 jar
    if (fs.existsSync(__dirname + '/.jar.tmp')) {
      const jarData = JSON.parse(fs.readFileSync(__dirname + '/.jar.tmp'));
      for (const [k, v] of Object.entries(jarData)) jar.set(k, v);
    } else {
      // 没有 jar 缓存，重新初始化
      jar = await client.initSession();
    }

    const r = await client.checkStatus(jar, client.hash33(qrsig));
    
    if (r.code === 0) {
      console.error('✅ 扫码成功:', r.nickname);
      const result = await client.completeLogin(r.callbackUrl, jar, r.nickname);
      const data = { 
        gameCookies: result.gameCookies, 
        nickname: result.nickname, 
        loginTime: new Date().toISOString() 
      };
      save(data);
      try { fs.unlinkSync(__dirname + '/.jar.tmp'); } catch(e) {}
      try { fs.unlinkSync(__dirname + '/.qrsig.tmp'); } catch(e) {}
      out({ success: true, status: 'success', gameCookies: result.gameCookies, nickname: result.nickname });
    } else if (r.code === 66) {
      out({ success: true, status: 'waiting' });
    } else if (r.code === 67) {
      out({ success: true, status: 'scanned' });
    } else if (r.code === 65) {
      out({ success: true, status: 'expired' });
    } else {
      out({ success: true, status: 'unknown', code: r.code });
    }
  } catch (e) {
    out({ success: false, error: e.message });
  }
}

async function getGameCookies() {
  console.error('🚀 获取游戏 Cookie...');

  // 检查 YAML 文件是否有缓存
  const { accounts } = loadExistingYaml();
  if (accounts.length > 0) {
    const last = accounts[accounts.length - 1];
    if (last.openId) {
      console.error('✅ 使用缓存 Cookie');
      return out({ success: true, gameCookies: last, nickname: '已缓存账号' });
    }
  }

  console.error('📌 初始化...');
  jar = await client.initSession();

  console.error('📌 获取二维码...');
  const { qrImage, qrsig } = await client.getQrCode(jar);
  fs.writeFileSync('qrcode.png', Buffer.from(qrImage.split(',')[1], 'base64'));
  console.error('📱 二维码: qrcode.png');

  console.error('⏳ 等待扫码...');
  for (let i = 0; i < 120; i++) {
    const r = await client.checkStatus(jar, client.hash33(qrsig));
    if (r.code === 0) {
      console.error('✅ 扫码成功:', r.nickname);
      const result = await client.completeLogin(r.callbackUrl, jar, r.nickname);
      const data = { 
        gameCookies: result.gameCookies, 
        nickname: result.nickname, 
        loginTime: new Date().toISOString() 
      };
      save(data);
      console.error('📋', result.gameCookies);
      return out({ success: true, gameCookies: result.gameCookies, nickname: result.nickname });
    }
    if (r.code === 65) return out({ success: false, error: '二维码已过期' });
    process.stdout.write(`\r${r.code === 66 ? '⏳ 等待' : '📱 已扫码'}... (${i+1}/120) `);
    await new Promise(r => setTimeout(r, 1000));
  }
  out({ success: false, error: '扫码超时' });
}

const action = process.argv[2] || 'getGameCookies';
if (action === 'start') start();
else if (action === 'poll') poll();
else if (action === 'getGameCookies') getGameCookies();
else out({ success: false, error: '未知操作: ' + action });