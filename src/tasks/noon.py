from src.exceptions import ConfigKeyError
import asyncio
import random

from src.tasks.register import TaskModule, Registry
from src.utils.daledou import DaLeDou
from src.utils.date_time import DateTime
from .common import (
    c_get_material_quantity,
    c_get_doushenta_cd,
    c_邪神秘宝,
    c_帮派商会,
    c_任务派遣中心,
    c_侠士客栈,
    c_帮派巡礼,
    c_深渊秘境,
    c_龙凰论武,
    c_幸运金蛋,
    c_客栈同福,
    c_大笨钟,
)
from .daily_tasks import run_daily_tasks
from .daily_tasks import 助阵 as daily_助阵

registry = Registry(TaskModule.noon, schedule_time="13:01:00", description="午间任务")
register = registry.register


# ==================== 通用工具函数 ====================

def get_boss_id():
    """返回历练高等级到低等级场景每关最后两个BOSS的id"""
    for _id in range(6394, 6013, -20):
        yield _id
        yield _id - 1


async def exchange_items(d: DaLeDou, config_key: str, cost_type: int = 0):
    """通用兑换函数"""
    exchange_config: dict[int, dict] = d.config(config_key)
    cost_param = f"&costtype={cost_type}" if cost_type else ""
    for _id, item in exchange_config.items():
        quantity: int = item["quantity"]
        if quantity <= 0:
            continue
        quotient, remainder = divmod(quantity, 10)
        for _ in range(quotient):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=10{cost_param}")
            d.log(d.find())
            if "成功" not in d.html:
                break
        for _ in range(remainder):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=1{cost_param}")
            d.log(d.find())
            if "成功" not in d.html:
                break


async def check_tower_times(d: DaLeDou) -> bool:
    """检查华山论剑挑战次数"""
    await d.get("cmd=knightarena")
    times_res = d.find(r"今日已挑战\((\d+)/8\)")
    if times_res:
        today_times = int(times_res)
        d.log(f"今日已挑战 => {today_times}/8")
        if today_times >= 8:
            d.log("免费挑战次数已满，结束华山论剑全部任务")
            return False
    else:
        d.log("无法读取今日挑战次数，终止任务防止超限")
        return False
    return True


async def fight_boss(d: DaLeDou, uid: str, name: str, prefix: str = ""):
    """通用BOSS战斗"""
    if d.find(rf"(.*?B_UID={uid}.*?已乐斗)"):
        d.log(f"{prefix}{name} => 今日已乐斗，跳过")
        return True

    await d.get(f"cmd=fight&B_UID={uid}")
    if "使用规则" in d.html:
        msg = d.find(r"】</p><p>(.*?)<br />")
        d.log(f"{prefix}{msg if msg else '战斗限制'}")
    else:
        result = d.find(r"<br />(.*?)！")
        d.log(f"{prefix}{result.strip()}" if result else f"{prefix}{name} 战斗结果无法解析")

    if "体力值不足" in d.html:
        d.log(f"{prefix}体力不足，终止后续挑战")
        return False
    return True


async def do_exchange(d: DaLeDou, _id: int, times: int, cost_param: str = ""):
    """执行兑换"""
    await d.get(f"cmd=exchange&subtype=2&type={_id}&times={times}{cost_param}")
    return d.find()


# ==================== 任务函数 ====================

@register()
async def 邪神秘宝(d: DaLeDou):
    await c_邪神秘宝(d)

@register()
async def 元武阁(d: DaLeDou):
    """元武阁宝匣开启 - 边开边领，直到没有宝匣且没有可领取奖励"""
    
    # 从配置中读取开关（配置必须存在）
    enabled = d.config('元武阁.enabled')
    
    if enabled == 0:
        return
    
    import re
    
    # 提取结果函数
    def extract_result(html):
        """提取操作结果（开箱或领取）"""
        # 方法1：匹配 "您打开X个XXX宝匣" 开头的结果（开箱）
        match = re.search(r'您打开\d+个[^，]*宝匣[^<]*(?:<br\s*/?>)?', html)
        if match:
            result = re.sub(r'<br\s*/?>', '', match.group(0))
            return result.strip()
        
        # 方法2：匹配 "恭喜获得" 格式（开箱）
        match = re.search(r'恭喜获得[^<]*(?:<br\s*/?>)?', html)
        if match:
            result = re.sub(r'<br\s*/?>', '', match.group(0))
            return result.strip()
        
        # 方法3：匹配 <p> 标签内容（领取或通用）
        match = re.search(r'<p>([^<]+)</p>', html)
        if match:
            return match.group(1).strip()
        
        return None
    
    # 品级对应的 boxId 和优先级
    box_id_map = {
        '凡品宝匣': 1,
        '玄品宝匣': 2,
        '地品宝匣': 3,
        '天品宝匣': 4,
        '神品宝匣': 5,
        '圣品宝匣': 6
    }
    
    priority = {
        '圣品宝匣': 6,
        '神品宝匣': 5,
        '天品宝匣': 4,
        '地品宝匣': 3,
        '玄品宝匣': 2,
        '凡品宝匣': 1
    }
    
    total_opened = 0
    total_claimed = 0
    max_rounds = 100
    
    for _ in range(max_rounds):
        await d.get("cmd=career&op=view&sub=treasure")
        
        # 解析宝匣数量
        boxes = []
        pattern = r'◆\s*<a[^>]*>([^<]+)</a>\s*：\s*(\d+)\s*个'
        matches = re.findall(pattern, d.html)
        
        for name, count in matches:
            name = name.strip()
            count = int(count)
            if count > 0 and name in box_id_map:
                boxes.append({
                    'name': name,
                    'count': count,
                    'boxId': box_id_map[name],
                    'priority': priority.get(name, 0)
                })
        
        boxes.sort(key=lambda x: x['priority'], reverse=True)
        
        # 优先领取奖励（灵气满时产生的宝匣）
        if re.search(r'领取全部', d.html):
            await d.get("cmd=career&op=mclaimall")
            result = extract_result(d.html)
            if result:
                d.log(result)
            total_claimed += 1
            continue
        
        if re.search(r'[品神圣天地玄凡]?宝匣\s*\*?\s*\d+\s*[&nbsp;]*\s*<a[^>]*href="[^"]*mclaim"', d.html):
            await d.get("cmd=career&op=mclaim")
            result = extract_result(d.html)
            if result:
                if "无可领取" not in result and "暂无可领取" not in result:
                    total_claimed += 1
                d.log(result)
            continue
        
        # 没有可领取的奖励，开启宝匣
        if not boxes:
            break
        
        # 按优先级开启宝匣（只开一批就重新循环）
        opened = False
        for box in boxes:
            name = box['name']
            count = box['count']
            box_id = box['boxId']
            
            if count <= 0:
                continue
            
            # 开百个（仅天品）
            if count >= 100 and box_id == 4:
                await d.get(f"cmd=career&op=boxopen&boxId={box_id}&count=100")
                result = extract_result(d.html)
                if result:
                    d.log(result)
                total_opened += 100
                opened = True
                break
            
            # 开十个
            if count >= 10:
                await d.get(f"cmd=career&op=boxopen&boxId={box_id}&count=10")
                result = extract_result(d.html)
                if result:
                    d.log(result)
                total_opened += 10
                opened = True
                break
            
            # 开单个
            if count >= 1:
                await d.get(f"cmd=career&op=boxopen&boxId={box_id}&count=1")
                result = extract_result(d.html)
                if result:
                    d.log(result)
                total_opened += 1
                opened = True
                break
        
        if not opened:
            break
    
    d.log(f"开启 {total_opened} 个，领取 {total_claimed} 次")

@register()
async def 华山论剑(d: DaLeDou):
    """每月1~25号挑战，26号领取赛季段位奖励、荣誉兑换"""
    day = DateTime.day()
    if not (1 <= day <= 26):
        return

    if day == 26:
        await d.get("cmd=knightarena&op=drawranking")
        d.log(d.find(r"</a><br />(.*?)<br />"))
        await exchange_items(d, "华山论剑.exchange")
        return

    knight_config = d.config("华山论剑.战阵调整")
    if not knight_config:
        d.log("你没有设置战阵调整，跳过挑战")
        return

    await d.get("cmd=knightarena&op=viewsetknightlist&pos=0")
    knight_data = dict(d.findall(r">([\u4e00-\u9fff]+) \d+级.*?knightid=(\d+)"))
    if not knight_data:
        d.log("战阵调整侠士不足，跳过挑战")
        return

    for item in knight_config:
        if not await check_tower_times(d):
            return

        is_fail = False
        for i, knight in enumerate(item["knights"], 1):
            if i > 3:
                break
            if _id := knight_data.get(knight):
                await d.get(f"cmd=knightarena&op=setknight&id={_id}&pos={i}&type=1")
                d.log(f"第{i}战 -> {knight}")
            else:
                d.log(f"第{i}战 -> 您没有{knight}")
                is_fail = True
                break

        if is_fail:
            d.log("当前编队出战侠士失败，跳过该编队挑战")
            continue

        for _ in range(item["count"]):
            if not await check_tower_times(d):
                return
            await d.get("cmd=knightarena&op=challenge")
            log_text = d.find(r"</a><br />(.*?)<br />")
            d.log(log_text)
            if "系统繁忙" in d.html:
                d.log("接口繁忙，继续下一轮")
                continue
            if "增加荣誉点数" not in d.html:
                break


@register()
async def 分享(d: DaLeDou):
    is_end = False
    floor_count = 0
    second = await c_get_doushenta_cd(d)
    skip_tower = False

    await d.get("cmd=sharegame&subtype=1")
    await d.get("cmd=sharegame&subtype=6")
    d.log(d.find(r"】</p>(.*?)<p>"))

    if "达到当日分享次数上限" in d.html:
        d.log(d.find(r"</p><p>(.*?)<br />"))
        d.log("分享次数已达上限，跳过斗神塔全部挑战")
        skip_tower = True

    if not skip_tower:
        for _ in range(8):
            if floor_count and floor_count % 10 == 0:
                await d.get("cmd=sharegame&subtype=6")
                d.log(d.find(r"】</p>(.*?)<p>"))
                if "达到当日分享次数上限" in d.html:
                    d.log(d.find(r"</p><p>(.*?)<br />"))
                    break

            for _ in range(10):
                if is_end:
                    break
                await d.get("cmd=towerfight&type=0")
                result_text = d.find()
                if "您战胜了" in d.html:
                    floor_count += 1
                    if floor_count % 10 == 0:
                        d.log(f"斗神塔 -> {result_text}")
                else:
                    d.log(f"斗神塔 -> {result_text}")
                    is_end = True
                    break
                await asyncio.sleep(second)
            if is_end:
                break

        await d.get("cmd=towerfight&type=11")
        d.log(f"斗神塔 -> {d.find()}")
        await asyncio.sleep(second)
        if "结束挑战" in d.html:
            await d.get("cmd=towerfight&type=7")
            d.log(f"斗神塔 -> {d.find()}")

    if DateTime.week() == 4:
        await d.get("cmd=sharegame&subtype=3")
        for s in d.findall(r"sharenums=(\d+)"):
            await d.get(f"cmd=sharegame&subtype=4&sharenums={s}")
            d.log(d.find(r"】</p>(.*?)<p>"))
            await asyncio.sleep(3)
        if d.html.count("已领取") == 14:
            await d.get("cmd=sharegame&subtype=7")
            d.log(d.find(r"】</p>(.*?)<p>"))


@register()
async def 好友(d: DaLeDou):
    """乐斗好友BOSS，仅处理侠：条目"""
    count = d.config("好友.贡献药水.count")
    for _ in range(count):
        await d.get("cmd=use&id=3038&store_type=1&page=1")
        if "使用规则" in d.html:
            msg = d.find(r"】</p><p>(.*?)<br />")
            d.log(f"好友：{msg if msg else '贡献药水使用异常'}")
            break

    await d.get("cmd=friendlist&page=1")
    for _, raw_name, uid in d.findall(r"(侠：)<a.*?>([^<>]+)</a>.*?B_UID=(\d+)"):
        name = raw_name.split("&nbsp;")[0].strip()
        if d.find(rf"(侠：.*?B_UID={uid}.*?已乐斗)"):
            d.log(f"BOSS {name} => 今日已乐斗，跳过")
            continue

        await d.get(f"cmd=fight&B_UID={uid}")
        if "使用规则" in d.html:
            msg = d.find(r"】</p><p>(.*?)<br />")
            d.log(f"{msg if msg else '战斗异常'}")
            break

        # 提取完整结果并清理
        result = d.find(r"<br />(.*?)<br />")
        if result:
            # 简单清理HTML标签（移除<和>之间的内容）
            clean_result = ""
            skip = False
            for char in result:
                if char == '<':
                    skip = True
                elif char == '>':
                    skip = False
                elif not skip:
                    clean_result += char
            # 截取到"当前体力值"之前
            if "当前体力值" in clean_result:
                clean_result = clean_result.split("当前体力值")[0].strip()
            d.log(clean_result)
        else:
            d.log(f"{name} 战斗结果无法解析")

        if "体力值不足" in d.html:
            d.log("体力不足，终止后续挑战")
            break


@register()
async def 帮友(d: DaLeDou):
    """乐斗帮友BOSS，仅处理侠：条目"""
    await d.get("cmd=viewmem&page=1")
    for _, raw_name, uid in d.findall(r"(侠：)<a.*?>([^<>]+)</a>.*?B_UID=(\d+)"):
        name = raw_name.split("&nbsp;")[0].strip()
        if d.find(rf"(侠：.*?B_UID={uid}.*?已乐斗)"):
            d.log(f"BOSS {name} => 今日已乐斗，跳过")
            continue

        await d.get(f"cmd=fight&B_UID={uid}")
        # 提取完整结果并清理
        result = d.find(r"<br />(.*?)<br />")
        if result:
            # 简单清理HTML标签
            clean_result = ""
            skip = False
            for char in result:
                if char == '<':
                    skip = True
                elif char == '>':
                    skip = False
                elif not skip:
                    clean_result += char
            # 截取到"当前体力值"之前
            if "当前体力值" in clean_result:
                clean_result = clean_result.split("当前体力值")[0].strip()
            d.log(clean_result)
        else:
            d.log(f"{name} 战斗结果无法解析")


@register()
async def 侠侣(d: DaLeDou):
    """每天乐斗侠侣BOSS、情师徒拜，每周二、五、日报名侠侣争霸"""
    enabled = d.config("侠侣.情师徒拜.enabled")

    await d.get("cmd=viewxialv&page=1")
    boss_list, friend_list = [], []
    for prefix, raw_name, uid in d.findall(r"((?:侠|情|师|拜)：)<a.*?>([^<>]+)</a>.*?B_UID=(\d+)"):
        name = raw_name.split("&nbsp;")[0].strip()
        (boss_list if prefix == "侠：" else friend_list).append({"uid": uid, "name": name})

    # 先检查体力
    if "体力值不足" in d.html:
        d.log("体力不足，终止挑战")
        return

    for data in boss_list:
        uid, name = data["uid"], data["name"]
        
        # 检查是否已乐斗
        if d.find(rf"(侠：.*?B_UID={uid}.*?已乐斗)"):
            d.log(f"BOSS {name} => 今日已乐斗，跳过")
            continue

        await d.get(f"cmd=fight&B_UID={uid}")
        if "使用规则" in d.html:
            msg = d.find(r"】</p><p>(.*?)<br />")
            d.log(f"{msg if msg else '战斗异常'}")
            break

        # 提取完整结果
        result = d.find(r"<br />(.*?)<br />")
        if result:
            # 简单清理HTML标签
            clean_result = ""
            skip = False
            for char in result:
                if char == '<':
                    skip = True
                elif char == '>':
                    skip = False
                elif not skip:
                    clean_result += char
            # 截取到"当前体力值"之前
            if "当前体力值" in clean_result:
                clean_result = clean_result.split("当前体力值")[0].strip()
            d.log(f"{clean_result}")
        else:
            d.log(f"{name} 战斗结果无法解析")

        # 检查体力是否不足
        if "体力值不足" in d.html:
            d.log("体力不足，终止后续挑战")
            return

    if enabled:
        for data in friend_list:
            uid, name = data["uid"], data["name"]
            
            # 检查是否已乐斗
            if d.find(rf"(情|师|拜)：.*?B_UID={uid}.*?已乐斗"):
                d.log(f"{name} => 今日已乐斗，跳过")
                continue

            await d.get(f"cmd=fight&B_UID={uid}")
            if "使用规则" in d.html:
                msg = d.find(r"】</p><p>(.*?)<br />")
                d.log(f"{msg if msg else '战斗异常'}")
                break

            result = d.find(r"<br />(.*?)<br />")
            if result:
                clean_result = ""
                skip = False
                for char in result:
                    if char == '<':
                        skip = True
                    elif char == '>':
                        skip = False
                    elif not skip:
                        clean_result += char
                if "当前体力值" in clean_result:
                    clean_result = clean_result.split("当前体力值")[0].strip()
                d.log(f"{clean_result}")
            else:
                d.log(f"{name} 战斗结果无法解析")

            # 检查体力是否不足
            if "体力值不足" in d.html:
                d.log("体力不足，终止后续挑战")
                return

    if DateTime.week() in {2, 5, 7}:
        await d.get("cmd=cfight&subtype=9")
        if "使用规则" in d.html:
            msg = d.find(r"】</p><p>(.*?)<br />")
        else:
            msg = d.find(r"报名状态.*?<br />(.*?)<br />")
        d.log(f"侠侣争霸：{msg if msg else '未获取报名信息'}")


@register()
async def 武林(d: DaLeDou):
    await d.get("cmd=fastSignWulin&ifFirstSign=1")
    if "使用规则" in d.html:
        d.log(d.find(r"】</p><p>(.*?)<br />"))
    else:
        d.log(d.find(r"升级。<br />(.*?) "))


@register()
async def 群侠(d: DaLeDou):
    knight_config = d.config("群侠.设置战队")
    if not knight_config:
        d.log("你必须设置战队才能报名")
        return

    await d.get("cmd=knightfight&op=viewsetknightlist&pos=0")
    if "报名状态：已报名" in d.html:
        d.log(d.find(r"报名状态：(.*?)<"))
        return

    knight_data = dict(d.findall(r">([\u4e00-\u9fff]+) \d+级.*?knightid=(\d+)"))
    if not knight_data:
        d.log("设置战队侠士不足")
        return

    for i, knight in enumerate(knight_config, 1):
        if i > 5:
            break
        if _id := knight_data.get(knight):
            await d.get(f"cmd=knightfight&op=set_knight&id={_id}&pos={i}&type=1")
            d.log(f"第{i}战 -> {knight}")
        else:
            d.log(f"第{i}战 -> 您没有{knight}，跳过报名")
            return

    await d.get("cmd=knightfight&op=signup")
    d.log(d.find(r"侠士侠号.*?<br />(.*?)<br />"))


@register()
async def 结拜(d: DaLeDou):
    if DateTime.week() not in {1, 2}:
        return
    for _id in [1, 2, 3, 5, 4]:
        await d.get(f"cmd=brofight&subtype=1&gidIdx={_id}")
        d.log(d.find(r"排行</a><br />(.*?)<"))
        if "请换一个赛区报名" not in d.html and "你们无法报名" not in d.html:
            break


@register()
async def 巅峰之战进行中(d: DaLeDou):
    if DateTime.week() in {1, 2}:
        await d.get("cmd=gvg&sub=1")
        d.log(d.find(r"】</p>(.*?)<br />"))
        _id = d.config("巅峰之战进行中.id")
        await d.get(f"cmd=gvg&sub=4&group={_id}&check=1")
        d.log(d.find(r"】</p>(.*?)<br />"))
        return

    for _ in range(14):
        await d.get("cmd=gvg&sub=5")
        if "战线告急" in d.html:
            d.log(d.find(r"支援！<br />(.*?)<"))
        else:
            d.log(d.find(r"】</p>(.*?)<"))
        if "你在巅峰之战中" not in d.html:
            break


@register()
async def 矿洞(d: DaLeDou):
    f = d.config("矿洞.floor")
    m = d.config("矿洞.mode")

    await d.get("cmd=newmercenary")
    mercenary_ids = []
    mercenary_all = d.findall(r'id=(\d+)">(.*?)</a>')
    mercenary_battle = d.findall(r"<br />\d+. (.*?) ")
    for _id, mercenary_name in mercenary_all:
        if mercenary_name in mercenary_battle:
            mercenary_ids.append(_id)

    if "10019" in d.html:
        await d.get("cmd=newmercenary&sub=3&id=10019")
        d.log(f"{d.find(r'！<br />(.*?)<')} -> {d.find()}")
        is_success = True
    else:
        is_success = False

    await d.get("cmd=factionmine")
    for _ in range(5):
        if "副本挑战中" in d.html:
            await d.get("cmd=factionmine&op=fight")
            d.log(d.find())
            if "挑战次数不足" in d.html:
                break
            await asyncio.sleep(1.5)
        elif "开启副本" in d.html:
            await d.get(f"cmd=factionmine&op=start&floor={f}&mode={m}")
            d.log(d.find())
            if "当前不能开启此副本" in d.html:
                break
        elif "领取奖励" in d.html:
            await d.get("cmd=factionmine&op=reward")
            d.log(d.find())

    if not is_success:
        return

    for _id in mercenary_ids:
        await d.get(f"cmd=newmercenary&sub=3&id={_id}")
        d.log(f"恢复{d.find(r'！<br />(.*?)<')} -> {d.find()}")


@register()
async def 掠夺(d: DaLeDou):
    week = DateTime.week()
    if week == 3:
        await d.get("cmd=forage_war&subtype=6")
        d.log(d.find())
        await d.get("cmd=forage_war&subtype=1")
        d.log(d.find())
        return

    if week != 2:
        return

    await d.get("cmd=forage_war")
    if "本轮轮空" in d.html or "未报名" in d.html:
        d.log(d.find(r"本届战况：(.*?)<br />"))
        return

    await d.get("cmd=forage_war&subtype=3")
    if gra_id := d.findall(r'gra_id=(\d+)">掠夺'):
        data = []
        for _id in gra_id:
            await d.get(f"cmd=forage_war&subtype=3&op=1&gra_id={_id}")
            if zhanli := d.find(r"<br />1.*? (\d+)\."):
                data.append((int(zhanli), _id))
        if data:
            _, _id = min(data)
            await d.get(f"cmd=forage_war&subtype=4&gra_id={_id}")
            d.log(d.find())
    else:
        d.log("已占领对方全部粮仓")

    await d.get("cmd=forage_war&subtype=5")
    d.log(d.find())


@register()
async def 踢馆(d: DaLeDou):
    week = DateTime.week()
    if week == 6:
        await d.get("cmd=facchallenge&subtype=1")
        d.log(d.find(r"</a><br />(.*?)<br />"))
        await asyncio.sleep(2)
        await d.get("cmd=facchallenge&subtype=7")
        d.log(d.find(r"</a><br />(.*?)<br />"))
        return

    if week != 5:
        return

    for t in [2, 2, 2, 2, 2, 4] + [3] * 30:
        await d.get(f"cmd=facchallenge&subtype={t}")
        d.log(d.find(r"</a><br />(.*?)<br />"))

        if any(x in d.html for x in [
            "抱歉，当前不在试练时间范围！",
            "当前不在挑战时间范围内，不能摇取多倍概率！",
            "抱歉，当前不在挑战时间范围！"
        ]):
            break
        if any(x in d.html for x in [
            "您的复活次数已耗尽",
            "您的挑战次数已用光",
            "你们帮没有报名参加这次比赛"
        ]):
            break
        await asyncio.sleep(2)


import asyncio

@register()
async def 竞技场(d: DaLeDou):
    exchange_list = [
        {"name": "河图洛书", "id": 5435, "enable": d.config("竞技场.河图洛书.enabled")},
        {"name": "神秘精华", "id": 3567, "enable": d.config("竞技场.神秘精华.enabled")},
        {"name": "神兵原石", "id": 3573, "enable": d.config("竞技场.神兵原石.enabled")},
        {"name": "软猥金丝", "id": 3574, "enable": d.config("竞技场.软猥金丝.enabled")},
        {"name": "精武之魂", "id": 3568, "enable": d.config("竞技场.精武之魂.enabled")},
        {"name": "守护之魂", "id": 3569, "enable": d.config("竞技场.守护之魂.enabled")},
    ]

    for item in exchange_list:
        if not item["enable"]:
            continue
        d.log(f"开始兑换【{item['name']}】×10")
        await d.get(f"cmd=arena&op=exchange&id={item['id']}&times=10")
        res = d.find()
        d.log(res)
        if "竞技点不足" in d.html:
            d.log("竞技点余额不足，终止所有兑换")
            break
        await asyncio.sleep(1.2)

    # 竞技场挑战循环，新增不在比赛时间判断
    for _ in range(10):
        await d.get("cmd=arena&op=challenge")
        msg = d.find()
        d.log(msg)
        if "免费挑战次数已用完" in d.html:
            break
        # 重点新增：竞技场未开放，直接终止挑战循环
        if "不在比赛时间内" in d.html:
            d.log("竞技场未开放，停止挑战")
            break
        await asyncio.sleep(0.8)

    await d.get("cmd=arena&op=drawdaily")
    d.log(d.find())


@register()
async def 十二宫(d: DaLeDou):
    _id = d.config("十二宫.id")
    await d.get(f"cmd=zodiacdungeon&op=autofight&scene_id={_id}")
    if "恭喜你" in d.html:
        d.log(d.find(r"恭喜你，(.*?)！"))
    elif "是否复活再战" in d.html:
        d.log(d.find(r"<br.*>(.*?)，"))
    else:
        d.log(d.find(r"<p>(.*?)<br />"))


@register()
async def 许愿(d: DaLeDou):
    for sub in [5, 1, 6]:
        await d.get(f"cmd=wish&sub={sub}")
        d.log(d.find())


@register()
async def 抢地盘(d: DaLeDou):
    """优先抢占守将**大侠(30级)的地盘，抢占成功后领取奖励"""
    import re

    await d.get("cmd=index")
    level = int(d.find(r'等级[：:]\s*(\d+)') or 0)
    if not level:
        return d.log("未找到等级信息，请检查登录状态")

    # 等级区间映射： (上限, type, 描述)
    ranges = [(30,1,"30级以下"),(40,2,"40级以下"),(50,3,"50级以下"),(60,4,"60级以下"),
              (70,5,"70级以下"),(80,6,"80级以下"),(90,7,"90级以下"),(100,8,"100级以下"),
              (110,9,"110级以下"),(120,10,"120级以下")]
    start = next((t for lim,t,desc in ranges if level < lim), 11)
    desc_map = {1:"30级以下",2:"40级以下",3:"50级以下",4:"60级以下",5:"70级以下",
                6:"80级以下",7:"90级以下",8:"100级以下",9:"110级以下",10:"120级以下",11:"无限制区域"}
    d.log(f"当前等级：{level}级 => 起始地盘类型：{desc_map[start]}")

    # 前置检测：今日记录或已有领地
    await d.get("cmd=viewmanorrecord&page=1")
    skip = bool(re.search(r'今天(&nbsp;|\s)*\d{1,2}[:：]\d{2}', d.html))
    if not skip:
        await d.get("cmd=viewmymanor")
        skip = bool(re.search(r'地盘名称\s*:', d.html))
        d.log("今日尚无抢地盘记录，且没有领地，准备抢占" if not skip else "检测到已有领地，跳过抢占")
    else:
        d.log("今日已抢地盘 => 跳过抢占")

    target_id = target_info = None
    if not skip:
        for t in range(start, 12):
            desc = desc_map[t]
            d.log(f"正在查找 {desc} 区域...")
            for i in range(1, 31):
                await d.get(f"cmd=recommendmanor&type={t}&page=1")
                if re.search(r'守将.*大侠\(30级\)', d.html):
                    for line in d.html.split('<br />'):
                        if '守将' in line and '大侠(30级)' in line:
                            m = re.search(r'manorid=(\d+)', line)
                            if m:
                                target_id = m.group(1)
                                nm = re.search(r'(\d+\s+[^\d]+\s+\d+级\s+守将[^\(]+大侠\(30级\))', line)
                                target_info = nm.group(1) if nm else line.split('攻占')[0].strip()
                                target_info = re.sub(r'^\d+\s+', '', target_info)
                                d.log(f"第{i}次刷新在 {desc} 找到目标：{target_info}")
                                break
                    if target_id:
                        break
            if target_id:
                break

        if target_id:
            d.log(f"正在抢占 {target_info}...")
            await d.get(f"cmd=manorfight&fighttype=1&manorid={target_id}")
            result = d.find(r"</p><p>(.*?)。") or d.find(r"<p>(.*?)。")
            d.log(result or "抢占完成，但未获取到结果信息")
        else:
            d.log("所有区域均未找到【守将**大侠(30级)】，放弃抢占")

    # 领取奖励
    await d.get("cmd=manorget&type=1")
    msgs = re.findall(r'<p>([^<]+)</p>', d.html)
    d.log(msgs[1] if len(msgs) >= 2 else "领取奖励完成（可能已领取或无需领取）")
        
@register()
async def 历练(d: DaLeDou):
    config = d.config("历练")

    await d.get("cmd=view&type=6")
    if "取消自动使用活力药水" in d.html:
        await d.get("cmd=set&type=11")
        d.log("取消自动使用活力药水")

    for _id, count in config.items():
        if count <= 0:
            continue
        for _ in range(count):
            await d.get(f"cmd=mappush&subtype=3&mapid=6&npcid={_id}&pageid=2")
            if "您还没有打到该历练场景" in d.html:
                d.log(d.find(r"介绍</a><br />(.*?)<br />"))
                break
            d.log(d.find(r"阅历值：\d+<br />(.*?)<br />"))
            if "活力不足" in d.html:
                return
            if "BOSS" not in d.html:
                break


@register()
async def 镖行天下(d: DaLeDou):
    # 1. 检查并完成护送
    await d.get("cmd=cargo")
    if "护送完成" in d.html:
        await d.get("cmd=cargo&op=16")
        d.log(d.find())

    # 2. 检查并刷新镖师
    if "剩余护送次数：1" in d.html:
        await d.get("cmd=cargo&op=7")
        count_match = d.find(r"免费刷新次数：(\d+)")
        count = int(count_match) if count_match else 0
        if not count_match:
            d.log("获取免费刷新次数失败，免费次数重置为0")

        for _ in range(count):
            d.log(d.find(r"当前镖师：(.*?)<"))
            if "蔡八斗" not in d.html:
                break
            await d.get("cmd=cargo&op=8")
            d.log(d.find())
        await d.get("cmd=cargo&op=6")
        d.log(d.find())

    # 3. 获取拦截次数配置
    target_power = None
    try:
        target_power = float(d.config("镖行天下.拦截最大战力"))
    except (ConfigKeyError, ValueError, TypeError):
        pass

    # 4. 先检查剩余拦截次数
    await d.get("cmd=cargo&op=3")
    
    remain_match = d.find(r"剩余拦截次数：(\d+)")
    if remain_match:
        if target_power is not None:
            d.log(f"当前剩余拦截次数：{remain_match} => 最高战力限制：{target_power}")
        else:
            d.log(f"当前剩余拦截次数：{remain_match}")
    else:
        d.log("获取剩余拦截次数失败")
    
    if "剩余拦截次数：0" in d.html:
        d.log("今日拦截次数已用完，终止拦截")
        return

    # 5. 开始拦截
    pattern = r"\d+\.(蔡八斗|温良恭|吕青橙|盛秋月)&nbsp;(.*?)&nbsp;.*?passerby_uin=(\d+)"
    for _ in range(10):
        await d.get("cmd=cargo&op=3")
        if "刷新过于频繁" in d.html:
            await asyncio.sleep(2)
            continue

        data_list = d.findall(pattern)
        if not data_list:
            await asyncio.sleep(2)
            continue

        for m, name, uin in data_list:
            # 拦截温良恭或盛秋月
            if m not in ["温良恭", "盛秋月"]:
                continue

            await d.get(f"cmd=totalinfo&B_UID={uin}")
            
            # 检查是否未开启资料访问授权
            if "该玩家未开启资料访问授权" in d.html:
                d.log(f"{name}({m}),镖头({uin}) => 未开启资料访问授权，跳过拦截")
                continue
            
            power_str = d.find(r"战斗力</a>:(\d+\.?\d*)")
            if power_str is None:
                d.log(f"{name}({m}),镖头({uin}/战力读取失败) => 跳过拦截")
                continue

            power = float(power_str)
            if target_power is not None and power > target_power:
                d.log(f"{name}({m}),镖头({uin}/战力{power:.1f}) => 超出限制，跳过拦截")
                continue

            # 拦截前再次检查剩余次数
            if "剩余拦截次数：0" in d.html:
                d.log("拦截次数已用完，终止拦截")
                return

            await d.get(f"cmd=cargo&op=14&passerby_uin={uin}")
            d.log(f"{name}({m}),镖头({uin}/战力{power:.1f}) => {d.find()}")
            
            # 拦截后检查剩余拦截次数
            if "剩余拦截次数：0" in d.html:
                d.log("拦截次数已用完，终止拦截")
                return

    
@register()
async def 幻境(d: DaLeDou):
    await d.get("cmd=misty")
    if "挑战次数：0/1" in d.html:
        d.log("您的挑战次数已用完，请明日再战！")
        await 兑换幻境积分(d)
        return

    if "累积星数" in d.html and "op=return" in d.html:
        await d.get("cmd=misty&op=return")

    try:
        start_id = int(d.config("幻境.id"))
    except (ConfigKeyError, ValueError, TypeError):
        start_id = 20
        d.log("未配置幻境.id或配置错误，默认从20开始向下寻找")

    select_id = None
    for sid in range(start_id, 0, -1):
        await d.get(f"cmd=misty&op=start&stage_id={sid}")
        if "副本未开通" not in d.html:
            select_id = sid
            d.log(f"选定幻境关卡：{sid}")
            break
        d.log(f"{sid} -> 副本未开通，尝试更低关卡")

    if select_id is None:
        d.log("1~20所有幻境关卡均未开通，任务结束")
        await 兑换幻境积分(d)
        return

    for _ in range(5):
        await d.get("cmd=misty&op=fight")
        d.log(d.find(r"星数.*?<br />(.*?)<br />"))
        if "尔等之才" in d.html:
            break

    for _ in range(10):
        b_id = d.find(r"box_id=(\d+)")
        if b_id is None:
            break
        await d.get(f"cmd=misty&op=reward&box_id={b_id}")
        d.log(d.find(r"星数.*?<br />(.*?)<br />"))

    await d.get("cmd=misty&op=return")
    
    # 挑战完成后兑换
    await 兑换幻境积分(d)


async def 兑换幻境积分(d: DaLeDou):
    """兑换幻境积分商品"""
    exchange_config = d.config("幻境.exchange")
    if not exchange_config:
        return
    
    await d.get("cmd=exchange&subtype=10&costtype=9")
    points_match = d.find(r"幻境积分[：:]\s*(\d+)")
    if not points_match:
        return
    points = int(points_match)
    
    for _id, quantity in exchange_config.items():
        if quantity <= 0 or points <= 0:
            continue
        
        quotient, remainder = divmod(quantity, 10)
        
        for _ in range(quotient):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=10&costtype=9")
            d.log(d.find())
            if "成功" not in d.html:
                break
        
        for _ in range(remainder):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=1&costtype=9")
            d.log(d.find())
            if "成功" not in d.html:
                break


@register()
async def 群雄逐鹿(d: DaLeDou):
    """周六报名、领奖"""
    if DateTime.week() != 6:
        return
    for op in ["signup", "drawreward"]:
        await d.get(f"cmd=thronesbattle&op={op}")
        d.log(d.find(r"届群雄逐鹿<br />(.*?)<br />"))


@register()
async def 画卷迷踪(d: DaLeDou):
    for _ in range(20):
        await d.get("cmd=scroll_dungeon&op=fight&buff=0")
        d.log(d.find(r"</a><br /><br />(.*?)<br />"))
        if "没有挑战次数" in d.html or "征战书不足" in d.html:
            break


@register()
async def 门派(d: DaLeDou):
    if d.config("门派.门派高香.enabled"):
        await d.get("cmd=exchange&subtype=2&type=1248&times=1")
        d.log(d.find())

    for op in ["fumigatefreeincense", "fumigatepaidincense"]:
        await d.get(f"cmd=sect&op={op}")
        d.log(d.find(r"修行。<br />(.*?)<br />"))

    ops = ["trainingwithnpc", "trainingwithmember"]
    if d.config("门派.门派战书.enabled"):
        await d.get("cmd=exchange&subtype=2&type=1249&times=1")
        d.log(d.find())
        if "成功" in d.html:
            ops.append("trainingwithmember")

    for op in ops:
        await d.get(f"cmd=sect&op={op}")
        d.log(d.find())

    ranks = [
        "rank=1&pos=1", "rank=2&pos=1", "rank=2&pos=2",
        "rank=3&pos=1", "rank=3&pos=2", "rank=3&pos=3", "rank=3&pos=4"
    ]
    for rank in ranks:
        await d.get(f"cmd=sect&op=trainingwithcouncil&{rank}")
        d.log(d.find())

    wuhuatang = await d.get("cmd=sect_task")
    tasks = {
        "进入华藏寺看一看": "cmd=sect_art",
        "进入伏虎寺看一看": "cmd=sect_trump",
        "进入金顶看一看": "cmd=sect&op=showcouncil",
        "进入八叶堂看一看": "cmd=sect&op=showtraining",
        "进入万年寺看一看": "cmd=sect&op=showfumigate",
    }
    for name, url in tasks.items():
        if name in wuhuatang:
            await d.get(url)
            d.log(name)

    if "查看一名" in wuhuatang:
        d.log("查看好友第二页所有成员")
        await d.get("cmd=friendlist&page=2")
        for uin in d.findall(r"</a>\d+.*?B_UID=(\d+)"):
            await d.get(f"cmd=totalinfo&B_UID={uin}")
            d.log(f"查看好友 -> {uin}")

    if "进行一次心法修炼" in wuhuatang:
        for _id in range(101, 119):
            await d.get(f"cmd=sect_art&subtype=2&art_id={_id}&times=1")
            d.log(d.find())
            if "修炼成功" in d.html:
                break

    await d.get("cmd=sect_task")
    for task_id in d.findall(r'task_id=(\d+)">完成'):
        await d.get(f"cmd=sect_task&subtype=2&task_id={task_id}")
        d.log(d.find())


@register()
async def 门派邀请赛(d: DaLeDou):
    if DateTime.week() in {1, 2}:
        await d.get("cmd=secttournament&op=signup")
        d.log(d.find())
        await d.get("cmd=secttournament&op=getrankandrankingreward")
        d.log(d.find())
        return

    for _ in range(10):
        await d.get("cmd=secttournament")
        if "挑战消耗：免费挑战" not in d.html:
            d.log("门派邀请赛：免费挑战次数耗尽，停止挑战")
            break
        await d.get("cmd=secttournament&op=fight")
        d.log(d.find())
        await asyncio.sleep(2)
        if "已达最大挑战上限" in d.html or "门派战书不足" in d.html:
            d.log("门派邀请赛：次数用尽/战书不足，停止挑战")
            break

    await exchange_items(d, "门派邀请赛.exchange")


@register()
async def 会武(d: DaLeDou):
    week = DateTime.week()
    if week in {1, 2, 3}:
        for _ in range(21):
            await d.get("cmd=sectmelee&op=dotraining")
            if "试炼场】" in d.html:
                d.log(d.find(r"最高伤害：\d+<br />(.*?)<br />"))
                continue
            d.log(d.find(r"规则</a><br />(.*?)<br />"))
            if "你已达今日挑战上限" in d.html:
                break
            if "你的试炼书不足" in d.html and d.config("会武.试炼书.enabled"):
                await d.get("cmd=exchange&subtype=2&type=1265&times=1&costtype=13")
                d.log(d.find())
                if "成功" not in d.html:
                    break

    elif week == 4:
        await d.get("cmd=sectmelee&op=cheer&sect=1003")
        await d.get("cmd=sectmelee&op=showcheer")
        d.log(d.find())
        await exchange_items(d, "会武.exchange", 13)

    elif week == 6:
        await d.get("cmd=sectmelee&op=showreward")
        d.log(d.find(r"<br />(.*?)。"))
        d.log(d.find(r"。<br />(.*?)。"))
        await d.get("cmd=sectmelee&op=drawreward")
        if "本届已领取奖励" in d.html:
            d.log(d.find(r"规则</a><br />(.*?)<br />"))
        else:
            d.log(d.find())


@register()
async def 梦想之旅(d: DaLeDou):
    await d.get("cmd=dreamtrip&sub=2")
    d.log(d.find())

    if DateTime.week() != 4:
        return

    quantity = d.config("梦想之旅.梦幻旅行.count")
    if d.html.count("已去过") < quantity:
        d.log(f"已去过数量低于{quantity}")
        return

    if place := d.findall(r"([\u4e00-\u9fa5\s\-]+)(?=\s未去过)"):
        bmapid = d.find(r'bmapid=(\d+)">梦幻旅行')
        for name in place:
            await d.get(f"cmd=dreamtrip&sub=3&bmapid={bmapid}")
            s = d.find(rf"{name}.*?smapid=(\d+)")
            await d.get(f"cmd=dreamtrip&sub=2&smapid={s}")
            d.log(d.find())

    for _ in range(2):
        if b := d.findall(r"sub=4&amp;bmapid=(\d+)"):
            await d.get(f"cmd=dreamtrip&sub=4&bmapid={b[0]}")
            d.log(d.find())


async def 问鼎天下_商店兑换(d: DaLeDou):
    """智能补足神魔录古阵篇宝物升级碎片材料"""
    name = "神魔录"
    data = {
        "夔牛鼓": {"id": 1, "t": 1270, "backpack_id": 5154, "material_name": "夔牛碎片"},
        "饕餮鼎": {"id": 2, "t": 1271, "backpack_id": 5155, "material_name": "饕餮碎片"},
        "烛龙印": {"id": 3, "t": 1268, "backpack_id": 5156, "material_name": "烛龙碎片"},
        "黄鸟伞": {"id": 4, "t": 1269, "backpack_id": 5157, "material_name": "黄鸟碎片"},
    }
    for treasure, _dict in data.items():
        _id = _dict["id"]
        t = _dict["t"]
        backpack_id = _dict["backpack_id"]
        material_name = _dict["material_name"]

        possess = await c_get_material_quantity(d, backpack_id)
        await d.get(f"cmd=ancient_gods&op=4&id={_id}")
        now_level = d.find(r"等级：(\d+)")
        max_level = d.find(r"最高提升至(\d+)")
        d.log(f"{treasure} -> 当前 {now_level} 级", name)
        d.log(f"{treasure} -> 最高 {max_level} 级", name)
        if now_level == max_level:
            continue

        need = d.find(r"碎片\*(\d+)")
        if need is None:
            d.log(f"{treasure} -> 获取{material_name}需要数量失败")
            continue

        need = int(need)
        if need <= possess:
            continue

        d.log(f"{treasure} -> 消耗{material_name}*{need}（{possess}）", name)
        q, r = divmod((need - possess), 10)
        if q:
            await d.get(f"cmd=exchange&subtype=2&type={t}&times=10&costtype=14")
            d.log(f"{treasure} -> {d.find()}")
            return
        for _ in range(r):
            await d.get(f"cmd=exchange&subtype=2&type={t}&times=1&costtype=14")
            d.log(f"{treasure} -> {d.find()}")


@register()
async def 问鼎天下(d: DaLeDou):
    week = DateTime.week()
    if week == 1:
        await d.get("cmd=tbattle&op=drawreward")
        d.log(d.find())
        await 问鼎天下_商店兑换(d)
    elif week == 6:
        _id = d.config("问鼎天下.淘汰赛")
        if _id is None:
            d.log("你没有设置淘汰赛助威帮派id")
            return
        await d.get(f"cmd=tbattle&op=cheerregionbattle&id={_id}")
        d.log(d.find())
    elif week == 7:
        _id = d.config("问鼎天下.排名赛")
        if _id is None:
            d.log("你没有设置排名赛助威帮派id")
            return
        await d.get(f"cmd=tbattle&op=cheerchampionbattle&id={_id}")
        d.log(d.find())
    else:
        await d.get("cmd=tbattle")
        if "放弃" in d.html:
            d.log("已有占领资源点，本任务结束")
            return

        if "你占领的领地已经枯竭" in d.html:
            await d.get("cmd=tbattle&op=drawreleasereward")
            d.log(d.find())

        remaining_occupy_count = d.find(r"剩余抢占次数：(\d+)")
        if remaining_occupy_count is None:
            d.log("获取剩余抢占次数失败")
            return

        remaining_occupy_count = int(remaining_occupy_count)
        if remaining_occupy_count == 0:
            d.log("没有抢占次数了")
            return

        region = d.config("问鼎天下.region")
        count = d.config("问鼎天下.count")
        if count >= remaining_occupy_count:
            count = max(0, remaining_occupy_count - 1)

        await d.get(f"cmd=tbattle&op=showregion&region={region}")
        for _id in d.findall(r"id=(\d+).*?攻占</a>")[:4]:
            while count:
                await d.get(f"cmd=tbattle&op=occupy&id={_id}&region={region}")
                d.log(d.find())
                if "你主动与" in d.html:
                    count -= 1
                    if "放弃" in d.html:
                        await d.get("cmd=tbattle&op=abandon")
                        d.log(d.find())
                else:
                    break
            if count == 0:
                break

        await d.get(f"cmd=tbattle&op=showregion&region={region}")
        _id = d.findall(r"id=(\d+).*?攻占</a>")[-1]
        await d.get(f"cmd=tbattle&op=occupy&id={_id}&region=1")
        d.log(d.find())


@register()
async def 帮派商会(d: DaLeDou):
    await c_帮派商会(d)


async def 帮派远征军_攻击(d: DaLeDou, p_id: str, u: str) -> bool:
    await d.get(f"cmd=factionarmy&op=fightWithUsr&point_id={p_id}&opp_uin={u}")
    if "加入帮派第一周不能参与帮派远征军" in d.html:
        return False
    if "【帮派远征军-征战结束】" in d.html:
        d.log(d.find())
        if "您未能战胜" in d.html:
            return False
    elif "【帮派远征军】" in d.html:
        d.log(d.find(r"<br /><br />(.*?)</p>"))
        if "您的血量不足" in d.html:
            return False
    return True


async def 帮派远征军_领取(d: DaLeDou):
    point_ids, land_ids = [], []
    for _id in range(5):
        await d.get(f"cmd=factionarmy&op=viewIndex&island_id={_id}")
        point_ids += d.findall(r'point_id=(\d+)">领取奖励')
        if "未解锁" in d.html:
            break
        land_ids += d.findall(r'island_id=(\d+)">领取岛屿宝箱')

    for p_id in point_ids:
        await d.get(f"cmd=factionarmy&op=getPointAward&point_id={p_id}")
        d.log(d.find())

    for i_id in land_ids:
        await d.get(f"cmd=factionarmy&op=getIslandAward&island_id={i_id}")
        d.log(d.find())


@register()
async def 帮派远征军(d: DaLeDou):
    while True:
        await d.get("cmd=factionarmy&op=viewIndex&island_id=-1")
        p_id = d.find(r'point_id=(\d+)">参战')
        if p_id is None:
            d.log("已经全部通关了")
            await 帮派远征军_领取(d)
            break

        await d.get(f"cmd=factionarmy&op=viewpoint&point_id={p_id}")
        data = []
        for _ in range(20):
            data += d.findall(r'(\d+)\.\d+<a.*?opp_uin=(\d+)">攻击')
            pages = d.find(r'pages=(\d+)">下一页')
            if not data or pages is None:
                break
            await d.get(f"cmd=factionarmy&op=viewpoint&point_id={p_id}&page={pages}")

        for _, u in sorted(data, key=lambda x: int(x[0])):
            if not await 帮派远征军_攻击(d, p_id, u):
                await 帮派远征军_领取(d)
                return


async def 帮派黄金联赛_参战(d: DaLeDou):
    await d.get("cmd=factionleague&op=2")
    if "opp_uin" not in d.html:
        d.log("敌人已全部阵亡")
        return

    data = []
    pages = int(d.find(r'pages=(\d+)">末页')) if d.find(r'pages=(\d+)">末页') else 1
    for p in range(1, pages + 1):
        await d.get(f"cmd=factionleague&op=2&pages={p}")
        data += d.findall(r"%&nbsp;&nbsp;(\d+).*?opp_uin=(\d+)")

    for _, u in sorted(data, key=lambda x: int(x[0])):
        await d.get(f"cmd=factionleague&op=4&opp_uin={u}")
        if "勇士，" in d.html:
            d.log(d.find())
            if "不幸战败" in d.html:
                return
        elif "您已阵亡" in d.html:
            d.log(d.find(r"<br /><br />(.*?)</p>"))
            return

    await d.get("cmd=factionleague&op=2")
    if "opp_uin" not in d.html:
        d.log("敌人已全部阵亡")


@register()
async def 帮派黄金联赛(d: DaLeDou):
    await d.get("cmd=factionleague&op=0")
    if "领取奖励" in d.html:
        await d.get("cmd=factionleague&op=5")
        d.log(d.find(r"<p>(.*?)<br /><br />"))
    elif "领取帮派赛季奖励" in d.html:
        await d.get("cmd=factionleague&op=7")
        d.log(d.find(r"<p>(.*?)<br /><br />"))
    elif "已参与防守" not in d.html:
        await d.get("cmd=factionleague&op=1")
        d.log(d.find(r"<p>(.*?)<br /><br />"))
    elif "休赛期" in d.html:
        d.log("休赛期无任何操作")

    if "op=2" in d.html:
        await 帮派黄金联赛_参战(d)


@register()
async def 任务派遣中心(d: DaLeDou):
    await c_任务派遣中心(d)


@register()
async def 武林盟主(d: DaLeDou):
    week = DateTime.week()
    if week in {3, 5, 7}:
        await d.get("cmd=wlmz&op=view_index")
        if data := d.findall(r'section_id=(\d+)&amp;round_id=(\d+)">'):
            for s, r in data:
                await d.get(f"cmd=wlmz&op=get_award&section_id={s}&round_id={r}")
                d.log(d.find(r"<br /><br />(.*?)</p>"))
        else:
            d.log("武林盟主：没有奖励领取")

    if week in {1, 3, 5}:
        try:
            _id = d.config("武林盟主.id")
        except Exception:  # ← 改成 Exception
            d.log("武林盟主：未配置【武林盟主】，跳过报名")
            return
        await d.get(f"cmd=wlmz&op=signup&ground_id={_id}")
        if "总决赛周不允许报名" in d.html or "您的战力不足" in d.html:
            d.log(d.find(r"战报</a><br />(.*?)<br />"))
        elif "您已报名" in d.html:
            d.log(d.find(r"赛场】<br />(.*?)<br />"))
    elif week in {2, 4, 6}:
        for index in range(8):
            await d.get(f"cmd=wlmz&op=guess_up&index={index}")
            d.log(d.find(r"规则</a><br />(.*?)<br />"))
        await d.get("cmd=wlmz&op=comfirm")
        d.log(d.find(r"战报</a><br />(.*?)<br />"))


@register()
async def 全民乱斗(d: DaLeDou):
    collect_status = False
    for t in [2, 3, 4]:
        await d.get(f"cmd=luandou&op=0&acttype={t}")
        for _id in d.findall(r'.*?id=(\d+)">领取</a>'):
            collect_status = True
            await d.get(f"cmd=luandou&op=8&id={_id}")
            d.log(d.find(r"斗】<br /><br />(.*?)<br />"))
    if not collect_status:
        d.log("没有礼包领取")


@register()
async def 侠士客栈(d: DaLeDou):
    await c_侠士客栈(d)
    await d.get("cmd=notice&op=view&sub=total")
    for _id in d.findall(r"giftId=(\d+)"):
        await d.get(f"cmd=notice&op=reqreward&giftId={_id}&sub=total")
        d.log(d.find(r"<p>.*?<br />(.*?)<"))


@register()
async def 江湖长梦(d: DaLeDou):
    if DateTime.day() != 20:
        return

    exchange_config = d.config("江湖长梦.exchange")
    for _id, item in exchange_config.items():
        material_name = item["material_name"]
        quantity = item["quantity"]
        if quantity <= 0:
            continue
        for _ in range(quantity):
            await d.get(f"cmd=longdreamexchange&op=exchange&key_id={_id}")
            if "成功" not in d.html:
                d.log(f"{material_name}*1 -> {d.find()}")
                break
            d.log(f"{material_name}*1 -> {d.find(r'</a><br />(.*?)<')}")


@register()
async def 大侠回归(d: DaLeDou):
    await d.get("cmd=newAct&subtype=173&op=1")
    if data := d.findall(r"subtype=(\d+).*?taskid=(\d+)"):
        for s, t in data:
            await d.get(f"cmd=newAct&subtype={s}&op=2&taskid={t}")
            d.log(d.find(r"】<br /><br />(.*?)<br />"))
    else:
        d.log("没有礼包领取")


async def 备战天赋(d: DaLeDou, _id: int):
    await d.get("cmd=ascendheaven&op=viewprepare")
    start_id = d.find(r"id=(\d+)")
    if start_id is None:
        return

    for i in range(int(start_id), _id + 1):
        while True:
            await d.get(f"cmd=ascendheaven&op=activeskill&id={i}")
            d.log(d.find())
            if "激活心法失败" in d.html:
                continue
            if "激活心法成功" in d.html:
                break
            return


@register()
async def 飞升大作战(d: DaLeDou):
    enabled = d.config("飞升大作战.玄铁令.enabled")
    t = d.config("飞升大作战.type")
    _id = d.config("飞升大作战.id")

    await d.get("cmd=ascendheaven")
    if "赛季结算中" in d.html:
        enabled = False

    if enabled and t in {1, 3}:
        for _ in range(1 if t == 1 else 2):
            await d.get("cmd=ascendheaven&op=exchange&id=2&times=1")
            d.log(d.find())

    await d.get(f"cmd=ascendheaven&op=signup&type={t}")
    d.log(d.find())
    if "你报名参加了" in d.html or "你已经报名参赛" in d.html:
        if t in {1, 3} and _id is not None:
            await 备战天赋(d, _id)
    else:
        await d.get("cmd=ascendheaven&op=signup&type=2")
        d.log(d.find())

    if DateTime.week() == 4:
        await d.get("cmd=ascendheaven")
        if "赛季结算中" in d.html:
            await d.get("cmd=ascendheaven&op=showrealm")
            for s in d.findall(r"season=(\d+)"):
                await d.get(f"cmd=ascendheaven&op=getrealmgift&season={s}")
                d.log(d.find())


async def 许愿帮铺(d: DaLeDou):
    exchange_config = d.config("深渊之潮.exchange")
    for _id, item in exchange_config.items():
        material_name = item["material_name"]
        quantity = item["quantity"]
        if quantity <= 0:
            continue

        quotient = quantity // 25 if "之书" in material_name else 0
        count = 0
        for _ in range(quotient):
            await d.get(f"cmd=abysstide&op=wishexchangetimes&id={_id}&times=25")
            d.log(d.find())
            if "成功" not in d.html:
                break
            count += 25
        for _ in range(quantity - count):
            await d.get(f"cmd=abysstide&op=wishexchange&id={_id}")
            d.log(d.find())
            if "成功" not in d.html:
                break


@register()
async def 深渊之潮(d: DaLeDou):
    await c_帮派巡礼(d)
    await c_深渊秘境(d)
    if DateTime.day() == 20:
        await 许愿帮铺(d)


@register()
async def 侠客岛(d: DaLeDou):
    await d.get("cmd=knight_island&op=viewmissionindex")
    pos = d.findall(r"viewmissiondetail&amp;pos=(\d+)")
    if not pos:
        for name, duration in d.findall(r"([^<>]+?)（需要.*?任务时长：([^<]+)"):
            d.log(f"{name} -> {duration}")
        return

    config = set(d.config("侠客岛.侠客行"))
    free_refresh_count = int(d.find(r"免费刷新剩余：(\d+)")) if d.find(r"免费刷新剩余：(\d+)") else 0
    if not free_refresh_count:
        d.log("获取免费刷新剩余失败，将免费次数重置为0")

    for p in pos:
        for _ in range(5):
            await d.get("cmd=knight_island&op=viewmissionindex")
            reward = d.find(rf'pos={p}">接受.*?任务奖励：([^<]+)')
            await d.get(f"cmd=knight_island&op=viewmissiondetail&pos={p}")
            task_name = d.find(r"([^>]+?)（")
            d.log(f"{task_name} -> {reward}")

            if reward not in config and free_refresh_count > 0:
                await d.get(f"cmd=knight_island&op=refreshmission&pos={p}")
                d.log(f"{task_name} -> {d.find(r'斗豆）<br />(.*?)<br />')}")
                free_refresh_count -= 1
                continue

            await d.get(f"cmd=knight_island&op=autoassign&pos={p}")
            d.log(f"{task_name} -> {d.find(r'）<br />(.*?)<br />')}")

            if "快速委派成功" in d.html:
                await d.get(f"cmd=knight_island&op=begin&pos={p}")
                d.log(f"{task_name} -> {d.find(r'斗豆）<br />(.*?)<br />')}")
                break

            if "符合条件侠士数量不足" in d.html and free_refresh_count > 0:
                await d.get(f"cmd=knight_island&op=refreshmission&pos={p}")
                d.log(f"{task_name} -> {d.find(r'斗豆）<br />(.*?)<br />')}")
                free_refresh_count -= 1
                continue

            d.log(f"{task_name} -> 没有免费刷新次数了")
            break


async def 八卦迷阵(d: DaLeDou):
    _data = {"离": 1, "坤": 2, "兑": 3, "乾": 4, "坎": 5, "艮": 6, "震": 7, "巽": 8}
    await d.get("cmd=spacerelic&op=goosip")
    result = d.find(r"([乾坤震巽坎离艮兑]{4})")
    if result is None:
        result = d.config("时空遗迹.八卦迷阵")

    for i in result:
        await d.get(f"cmd=spacerelic&op=goosip&id={_data[i]}")
        d.log(f"{i} -> {d.find(r'分钟<br /><br />(.*?)<br />')}")
        if "恭喜您" not in d.html:
            break

    if "恭喜您已通关迷阵" in d.html:
        await d.get("cmd=spacerelic&op=goosipgift")
        d.log(d.find(r"分钟<br /><br />(.*?)<br />"))


async def 遗迹商店(d: DaLeDou):
    exchange_config = d.config("时空遗迹.exchange")
    for _id, item in exchange_config.items():
        material_name = item["material_name"]
        quantity = item["quantity"]
        t = item["type"]
        if quantity <= 0:
            continue
        quotient, remainder = divmod(quantity, 10)
        for _ in range(quotient):
            await d.get(f"cmd=spacerelic&op=buy&type={t}&id={_id}&num=10")
            d.log(f"{material_name}*10 -> {d.find(r'售卖区.*?<br /><br /><br />(.*?)<')}")
            if "兑换成功" not in d.html:
                break
        for _ in range(remainder):
            await d.get(f"cmd=spacerelic&op=buy&type={t}&id={_id}&num=1")
            d.log(f"{material_name}*1 -> {d.find(r'售卖区.*?<br /><br /><br />(.*?)<')}")
            if "兑换成功" not in d.html:
                break


async def 异兽洞窟(d: DaLeDou):
    _ids = d.config("时空遗迹.异兽洞窟")
    if _ids is None:
        d.log("你没有设置异兽洞窟id")
        return

    for _id in _ids:
        await d.get(f"cmd=spacerelic&op=monsterdetail&id={_id}")
        if "剩余挑战次数：0" in d.html:
            d.log("异兽洞窟没有挑战次数")
            break
        if "剩余血量：0" in d.html:
            await d.get(f"cmd=spacerelic&op=saodang&id={_id}")
        else:
            await d.get(f"cmd=spacerelic&op=monsterfight&id={_id}")
        d.log(d.find(r"次数.*?<br /><br />(.*?)&"))
        if "请按顺序挑战异兽" in d.html:
            continue
        break


async def 悬赏任务(d: DaLeDou):
    data = []
    for t in [1, 2]:
        await d.get(f"cmd=spacerelic&op=task&type={t}")
        data += d.findall(r"type=(\d+)&amp;id=(\d+)")
    for t, _id in data:
        await d.get(f"cmd=spacerelic&op=task&type={t}&id={_id}")
        d.log(d.find(r"赛季任务</a><br /><br />(.*?)<"))
        if "您未完成该任务" in d.html:
            continue


async def 遗迹征伐(d: DaLeDou):
    await d.get("cmd=spacerelic&op=relicindex")
    year = d.find(r"(\d+)年")
    month = d.find(r"(\d+)月")
    day = d.find(r"(\d+)日")
    if year is None or month is None or day is None:
        d.log("获取结束日期失败")
        return

    current_date = DateTime.current_date()
    end_date = DateTime.get_offset_date(int(year), int(month), int(day))

    if current_date == end_date:
        await d.get("cmd=spacerelic&op=task&type=1&id=1")
        d.log(d.find(r"赛季任务</a><br /><br />(.*?)<"))
        await d.get("cmd=spacerelic&op=getrank")
        d.log(d.find(r"奖励</a><br /><br />(.*?)<"))
        await 遗迹商店(d)
        return

    end_date = DateTime.get_offset_date(int(year), int(month), int(day), 7)
    if current_date >= end_date:
        d.log("当前处于休赛期，结束前一天领取登录奖励、赛季奖励和悬赏商店兑换")
        return

    await 异兽洞窟(d)
    await d.get("cmd=spacerelic&op=bossfight")
    d.log(d.find(r"挑战</a><br />(.*?)&"))
    await 悬赏任务(d)


@register()
async def 时空遗迹(d: DaLeDou):
    await 八卦迷阵(d)
    await 遗迹征伐(d)


@register()
async def 世界树(d: DaLeDou):
    await d.get("cmd=worldtree")
    await d.get("cmd=worldtree&op=autoget&id=1")
    d.log(d.find(r"福宝<br /><br />(.*?)<br />"))

    async def get_id():
        for t in range(4):
            await d.get(f"cmd=worldtree&op=viewweaponpage&type={t}")
            for _id in d.findall(r"weapon_id=(\d+)"):
                await d.get(f"cmd=worldtree&op=setweapon&weapon_id={_id}&type={t}")
                d.log(d.find(r"当前武器：(.*?)<"))
                return _id
        return None

    await d.get("cmd=worldtree&op=viewexpandindex")
    if "免费温养" not in d.html:
        d.log("没有免费温养次数")
        return

    weapon_id = await get_id()
    if "weapon_id=0" in d.html and not weapon_id:
        d.log("没有武器可选择")
        return

    await d.get("cmd=worldtree&op=viewexpandindex")
    _id = d.find(r"weapon_id=(\d+)")
    await d.get(f"cmd=worldtree&op=dostrengh&times=1&weapon_id={_id}")
    d.log(d.find(r"规则</a><br />(.*?)<br />"))
    d.log(f"当前进度 -> {d.find(r'当前进度:(.*?)<')}")


async def 龙凰论武(d: DaLeDou):
    day = DateTime.day()
    if 1 <= day <= 3:
        _id = d.config("龙凰之境.龙凰论武.id")
        await d.get(f"cmd=dragonphoenix&op=sign&zone={_id}")
        d.log(d.find())
    elif 4 <= day <= 25:
        await c_龙凰论武(d)
        await d.get("cmd=dragonphoenix&op=gift")
        d.log(d.find(r"/\d+</a><br /><br />(.*?)<"))
    elif day == 27:
        await d.get("cmd=dragonphoenix&op=rankreward")
        d.log(d.find(r"<br /><br /><br />(.*?)<"))


async def 龙凰云集(d: DaLeDou):
    if DateTime.day() != 27:
        return

    await d.get("cmd=dragonphoenix&op=yunji")
    for _id in d.findall(r"idx=(\d+)"):
        await d.get(f"cmd=dragonphoenix&op=reward&idx={_id}")
        d.log(d.find(r"<br /><br /><br />(.*?)<"))
        if "当前无可领取奖励" in d.html:
            break

    exchange_config = d.config("龙凰之境.exchange")
    for _id, item in exchange_config.items():
        material_name = item["material_name"]
        quantity = item["quantity"]
        if quantity <= 0:
            continue
        quotient, remainder = divmod(quantity, 10)
        for _ in range(quotient):
            await d.get(f"cmd=dragonphoenix&op=buy&id={_id}&num=10")
            d.log(f"{material_name}*10 -> {d.find(r'<br /><br /><br />(.*?)<')}")
            if "成功" not in d.html:
                break
        for _ in range(remainder):
            await d.get(f"cmd=dragonphoenix&op=buy&id={_id}&num=1")
            d.log(f"{material_name}*1 -> {d.find(r'<br /><br /><br />(.*?)<')}")
            if "成功" not in d.html:
                break


async def 龙吟破阵(d: DaLeDou):
    if 1 <= DateTime.day() <= 3:
        await d.get("cmd=dragonphoenix&op=getlastreward")
        d.log(d.find(r"领取<br /><br />(.*?)<"))


@register()
async def 龙凰之境(d: DaLeDou):
    await 龙凰论武(d)
    await 龙凰云集(d)
    await 龙吟破阵(d)
        
@register()
async def 任务(d: DaLeDou):
    """日常任务"""
    await run_daily_tasks(d)

@register()
async def 我的帮派(d: DaLeDou):
    await d.get("cmd=factionop&subtype=3&facid=0")
    if "你的职位" not in d.html:
        d.log("您还没有加入帮派")
        return

    await 帮派供奉(d)
    await 帮派任务(d)

    if DateTime.week() == 7:
        subs = [4, 9, 6] if d.config("我的帮派.帮战.enabled") else [4, 6]
        for sub in subs:
            await d.get(f"cmd=facwar&sub={sub}")
            d.log(d.find(r"</p>(.*?)<br /><a.*?查看上届"))
            
async def 帮派供奉(d: DaLeDou):
    _ids = d.config("我的帮派.帮派供奉")
    if not _ids:
        return

    for _id in _ids:
        await d.get(f"cmd=oblation&id={_id}&page=1")
        if "供奉成功" in d.html:
            d.log(f"{_id} -> {d.find()}")
        else:
            d.log(f"{_id} -> {d.find(r'】</p><p>(.*?)<br />')}")
        if "每天最多供奉5次" in d.html:
            break

async def 帮派任务(d: DaLeDou):
    task_html = await d.get("cmd=factiontask&sub=1")
    tasks = {
        "帮战冠军": "cmd=facwar&sub=4",
        "查看帮战": "cmd=facwar&sub=4",
        "查看帮贡": "cmd=factionhr&subtype=14",
        "查看祭坛": "cmd=altar",
        "查看踢馆": "cmd=facchallenge&subtype=0",
        "查看要闻": "cmd=factionop&subtype=8&pageno=1&type=2",
        "粮草掠夺": "cmd=forage_war",
    }
    for name, url in tasks.items():
        if name in task_html:
            await d.get(url)
            d.log(name)

    if "帮派修炼" in task_html:
        count = 0
        for _id in [2727, 2758, 2505, 2536, 2437, 2442, 2377, 2399, 2429]:
            for _ in range(4):
                await d.get(f"cmd=factiontrain&type=2&id={_id}&num=1&i_p_w=num%7C")
                d.log(d.find(r"规则说明</a><br />(.*?)<br />"))
                if "技能经验增加" in d.html:
                    count += 1
                    continue
                break
            if count == 4:
                break

    await d.get("cmd=factiontask&sub=1")
    for _id in d.findall(r'id=(\d+)">领取奖励</a>'):
        await d.get(f"cmd=factiontask&sub=3&id={_id}")
        d.log(d.find(r"日常任务</a><br />(.*?)<br />"))

@register()
async def 帮派祭坛(d: DaLeDou):
    await d.get("cmd=altar")

    spin_times_text = d.find(r"剩余次数：(\d+)")
    if spin_times_text and int(spin_times_text) <= 0:
        d.log("帮派：轮盘剩余次数为0，跳过转动轮盘流程")
    else:
        for _ in range(30):
            if "转动轮盘" in d.html:
                await d.get("cmd=altar&op=spinwheel")
                if "转动轮盘" in d.html:
                    d.log(d.find())
                if "转转券不足" in d.html or "已达转转券转动次数上限" in d.html:
                    break
            if "【随机分配】" in d.html:
                all_disbanded = True
                data = d.findall(r"op=(.*?)&amp;id=(\d+)")
                for op, _id in data:
                    await d.get(f"cmd=altar&op={op}&id={_id}")
                    if "选择路线" in d.html:
                        await d.get(f"cmd=altar&op=dosteal&id={_id}")
                    if "该帮派已解散" in d.html or "系统繁忙" in d.html:
                        d.log(d.find(r"<br /><br />(.*?)<br />"))
                        continue
                    all_disbanded = False
                    if "转动轮盘" in d.html:
                        d.log(d.find())
                        break
                if all_disbanded and data:
                    break
            if "领取奖励" in d.html:
                await d.get("cmd=altar&op=drawreward")
                d.log(d.find())

    await d.get("cmd=exchange&subtype=10&costtype=12")
    exchange_config = d.config("帮派祭坛.exchange")
    for _id, item in exchange_config.items():
        material_name = item["material_name"]
        quantity = item["quantity"]
        if quantity <= 0:
            continue
        quotient, remainder = divmod(quantity, 10)
        for _ in range(quotient):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=10&costtype=12")
            d.log(f"祭坛兑换 {material_name}*10 -> {d.find()}")
            if "成功" not in d.html:
                break
        for _ in range(remainder):
            await d.get(f"cmd=exchange&subtype=2&type={_id}&times=1&costtype=12")
            d.log(f"祭坛兑换 {material_name}*1 -> {d.find()}")
            if "成功" not in d.html:
                break


@register()
async def 每日奖励(d: DaLeDou):
    for key in ["login", "meridian", "daren", "wuzitianshu"]:
        await d.get(f"cmd=dailygift&op=draw&key={key}")
        d.log(d.find())


@register()
async def 领取徒弟经验(d: DaLeDou):
    await d.get("cmd=exp")
    d.log(d.find(r"每日奖励</a><br />(.*?)<br />"))


@register()
async def 今日活跃度(d: DaLeDou):
    """今日活跃度任务"""
    
    # 今日活跃度
    await d.get("cmd=liveness")
    if activity_level := d.find(r"今日活跃度：(\d+)"):
        num = int(activity_level)
        if num < 80:
            has_task_15 = "15.[0/1]" in d.html
            has_task_16 = "16.[0/3]" in d.html

            # 优先级：77 > 75 > 72
            actions = []
            if num >= 77 and has_task_16:
                actions.append(daily_助阵)
            elif num >= 75 and has_task_15:
                actions.append(增强经脉)
            elif num >= 72 and has_task_15 and has_task_16:
                actions.extend([daily_助阵, 增强经脉])

            if actions:
                d.log(activity_level)
                for action in actions:
                    # 包装执行，让日志合并
                    action_name = "助阵" if action == daily_助阵 else "增强经脉"
                    # 保存原logger方法，临时替换
                    original_log = d.log
                    
                    # 定义新的log方法，包装日志内容
                    def wrapped_log(msg):
                        if "提升成功" in str(msg) or "经验增加" in str(msg):
                            original_log(f"今日活跃度：（{action_name}）{msg}")
                        else:
                            original_log(msg)
                    
                    d.log = wrapped_log
                    await action(d)
                    d.log = original_log
    
    # 今日活跃度 - 再次获取显示最终活跃度
    await d.get("cmd=liveness")
    d.log(d.find(r"今日活跃度：(\d+)"))
    if "帮派总活跃" in d.html:
        d.log(d.find(r"帮派总活跃：(.*?)<"))

    # 领取今日活跃度礼包
    for giftbag_id in range(1, 5):
        await d.get(f"cmd=liveness_getgiftbag&giftbagid={giftbag_id}&action=1")
        d.log(d.find(r"】<br />(.*?)<p>"))

    # 领取帮派总活跃奖励
    await d.get("cmd=factionop&subtype=18")
    if "创建帮派" in d.html:
        d.log(d.find(r"帮派</a><br />(.*?)<br />"))
    else:
        d.log(d.find())


@register()
async def 仙武修真(d: DaLeDou):
    # 1. 先领取任务奖励
    for task_id in range(1, 4):
        await d.get(f"cmd=immortals&op=getreward&taskid={task_id}")
        d.log(d.find(r"帮助</a><br />(.*?)<br />"))

    # 2. 获取剩余挑战次数
    count = d.find(r"剩余挑战次数：(\d+)")
    if count is None:
        d.log("获取挑战次数失败")
        return

    # 3. 执行挑战
    for _ in range(int(count)):
        _id = random.choice([1, 2, 3])
        await d.get(f"cmd=immortals&op=visitimmortals&mountainId={_id}")
        d.log(d.find(r"帮助</a><br />(.*?)<"))
        d.log(f"本次寻访：{d.find(r'本次寻访：.*?>(.*?)<')}")
        await d.get("cmd=immortals&op=fightimmortals")
        d.log(d.find(r"帮助</a><br />(.*?)<"))

    # 4. 挑战完成后进行炼化
    await d.get("cmd=immortals&op=smeltall")
    d.log(d.find(r"</a><br />(.*?)<br />"))


@register()
async def 乐斗黄历(d: DaLeDou):
    await d.get("cmd=calender&op=2")
    d.log(d.find(r"<br /><br />(.*?)<br />"))
    await d.get("cmd=calender&op=4")
    d.log(d.find(r"<br /><br />(.*?)<br />"))


@register()
async def 器魂附魔(d: DaLeDou):
    for _id in range(1, 4):
        await d.get(f"cmd=enchant&op=gettaskreward&task_id={_id}")
        d.log(d.find())


@register()
async def 兵法(d: DaLeDou):
    week = DateTime.week()
    if week == 4:
        await d.get("cmd=brofight&subtype=13")
        _id = d.find(r"teamid=(\d+).*?助威</a>")
        await d.get(f"cmd=brofight&subtype=13&teamid={_id}&type=5&op=cheer")
        d.log(d.find(r"领奖</a><br />(.*?)<br />"))

    if week == 6:
        await d.get("cmd=brofight&subtype=13&op=draw")
        d.log(d.find(r"领奖</a><br />(.*?)<br />"))

        for t in range(1, 6):
            await d.get(f"cmd=brofight&subtype=10&type={t}")
            for remainder, u in d.findall(r"50000.*?(\d+).*?champion_uin=(\d+)"):
                if remainder != "0":
                    await d.get(f"cmd=brofight&subtype=10&op=draw&champion_uin={u}&type={t}")
                    d.log(d.find(r"排行</a><br />(.*?)<br />"))
                    return


async def 点亮(d: DaLeDou) -> bool:
    await d.get("cmd=hallowmas&gb_id=1")
    while True:
        if cushaw_id := d.findall(r"cushaw_id=(\d+)"):
            c_id = random.choice(cushaw_id)
            await d.get(f"cmd=hallowmas&gb_id=4&cushaw_id={c_id}")
            d.log(d.find())
            if "活力" in d.html:
                return True
        if "请领取今日的活跃度礼包来获得蜡烛吧" in d.html:
            break
    return False


async def 点亮南瓜灯(d: DaLeDou):
    await d.get("cmd=view&type=6")
    if "取消自动使用活力药水" in d.html:
        await d.get("cmd=set&type=11")
        d.log("取消自动使用活力药水")

    for _id in get_boss_id():
        count = 3
        while count:
            await d.get(f"cmd=mappush&subtype=3&npcid={_id}&pageid=2")
            if "您还没有打到该历练场景" in d.html:
                d.log(d.find(r"介绍</a><br />(.*?)<br />"), "历练")
                break
            d.log(d.find(r"\d+<br />(.*?)<"), "历练")
            if "活力不足" in d.html:
                if not await 点亮(d):
                    return
                continue
            if "BOSS" not in d.html:
                break
            count -= 1


@register()
async def 万圣节(d: DaLeDou):
    await 点亮南瓜灯(d)
    await d.get("cmd=hallowmas")
    month, day = d.findall(r"(\d+)月(\d+)日6点")[0]
    end_date = DateTime.get_offset_date(DateTime.year(), int(month), int(day))
    if DateTime.current_date() == end_date:
        await d.get("cmd=hallowmas&gb_id=6")
        d.log(d.find())
        await d.get("cmd=hallowmas&gb_id=5")
        d.log(d.find())


@register()
async def 乐斗能量(d: DaLeDou):
    await d.get("cmd=newAct&subtype=108&op=0")
    data = d.findall(r"id=(\d+)")
    if not data:
        d.log("没有可领取的能量棒")
        return

    await d.get("cmd=view&type=6")
    if "取消自动使用活力药水" in d.html:
        await d.get("cmd=set&type=11")
        d.log("取消自动使用活力药水")

    for _id in get_boss_id():
        count = 3
        while count:
            await d.get(f"cmd=mappush&subtype=3&npcid={_id}&pageid=2")
            if "您还没有打到该历练场景" in d.html:
                d.log(d.find(r"介绍</a><br />(.*?)<br />"), "历练")
                break
            d.log(d.find(r"\d+<br />(.*?)<"), "历练")
            if "活力不足" in d.html:
                if not data:
                    return
                await d.get(f"cmd=newAct&subtype=108&op=1&id={data.pop()}")
                d.log(d.find(r"<br /><br />(.*?)<"))
                continue
            if "BOSS" not in d.html:
                break
            count -= 1


@register()
async def 大笨钟(d: DaLeDou):
    await c_大笨钟(d)


@register()
async def 幸运金蛋(d: DaLeDou):
    await c_幸运金蛋(d)


@register()
async def 客栈同福(d: DaLeDou):
    await c_客栈同福(d)


@register()
async def 反向历练(d: DaLeDou, link_text: str):
    if not d.config(f"{link_text}.历练.enabled"):
        return

    await d.get("cmd=view&type=6")
    if "开启自动使用活力药水" in d.html:
        await d.get("cmd=set&type=11")
        d.log("历练 -> 开启自动使用活力药水")

    for _id in get_boss_id():
        for _ in range(3):
            await d.get(f"cmd=mappush&subtype=3&mapid=6&npcid={_id}&pageid=2")
            if "您还没有打到该历练场景" in d.html:
                d.log(f"历练 -> {d.find(r'介绍</a><br />(.*?)<br />')}")
                break
            d.log(f"历练 -> {d.find(r'阅历值：\d+<br />(.*?)<br />')}")
            if "活力不足" in d.html or "活力药水使用次数已达到每日上限" in d.html:
                return
            if "BOSS" not in d.html:
                break


@register()
async def 节日福利(d: DaLeDou):
    await 反向历练(d, "节日福利")


@register()
async def 双旦福利(d: DaLeDou):
    await 反向历练(d, "双旦福利")


@register()
async def 金秋福利(d: DaLeDou):
    await 反向历练(d, "金秋福利")


@register()
async def 春节福利(d: DaLeDou):
    await 反向历练(d, "春节福利")


@register()
async def 多倍福利(d: DaLeDou):
    await 反向历练(d, "多倍福利")


@register()
async def 新春拜年(d: DaLeDou):
    await d.get("cmd=newAct&subtype=147")
    if "op=1" in d.html:
        for i in random.sample(range(5), 3):
            await d.get(f"cmd=newAct&subtype=147&op=1&index={i}")
        await d.get("cmd=newAct&subtype=147&op=2")
        d.log("已赠礼")