"""
日常任务模块
包含任务状态检测和各个任务的具体执行逻辑
"""

import asyncio
import re

from src.utils.daledou import DaLeDou


# ==================== 任务状态检测 ====================

async def get_task_list(d: DaLeDou) -> list[dict]:
    """
    从任务主页获取任务列表及状态
    """
    await d.get("cmd=task&sub=1")
    
    task_pattern = r'cmd=task&amp;sub=5&amp;id=(\d+)">([^<]+)</a>'
    
    tasks = []
    for match in d.findall(task_pattern):
        task_id, task_name = match
        if task_name in ["已完成", "未完成", "替换任务"]:
            continue
        if not task_name.strip():
            continue
        tasks.append({"id": task_id, "name": task_name.strip()})
    
    for task in tasks:
        task_id = task["id"]
        if f'cmd=task&amp;sub=5&amp;id={task_id}">已完成' in d.html:
            task["status"] = "已完成"
        elif f'cmd=task&amp;sub=3&amp;id={task_id}">替换任务' in d.html:
            task["status"] = "未完成"
        else:
            task["status"] = "未完成"
    
    return tasks


async def get_task_status_from_detail(d: DaLeDou, task_id: str) -> str:
    """
    从任务详情页获取任务状态
    """
    await d.get(f"cmd=task&sub=5&id={task_id}&despage=1")
    
    if '">已完成</a>' in d.html:
        return "已完成"
    elif '">替换任务</a>' in d.html:
        return "未完成"
    else:
        return "未知"


# ==================== 任务执行函数 ====================

async def 增强经脉(d: DaLeDou):
    await d.get("cmd=intfmerid&sub=1")
    if "关闭" in d.html:
        await d.get("cmd=intfmerid&sub=19")
        d.log(d.find())
    if "取消" in d.html and "doudou=0" in d.html:
        await d.get("cmd=intfmerid&sub=21&doudou=0")
        d.log(d.find())

    count_match = d.find(r"传功符</a>:(\d+)")
    if count_match is None:
        d.log("⚠️ 获取传功符数量失败")
        return

    count = int(count_match)
    if count < 200:
        d.log(f"⚠️ 传功符不足 (当前:{count})")
        return

    success_count = 0
    for i in range(12):
        await d.get("cmd=intfmerid&sub=1")
        _id = d.find(r'master_id=(\d+)">传功</a>')
        if _id is None:
            break
            
        await d.get(f"cmd=intfmerid&sub=2&master_id={_id}")
        if "传功符不足！" in d.html:
            d.log("⚠️ 传功符不足")
            return
            
        await d.get("cmd=intfmerid&sub=5")
        await d.get("cmd=intfmerid&sub=10&op=4")
        result = d.find()
        if "成功" in result:
            success_count += 1
        await asyncio.sleep(0.3)
    
    if success_count > 0:
        d.log(f"✅ 增强经脉 {success_count} 次")


async def 助阵(d: DaLeDou):
    data = {
        1: [0], 2: [0, 1], 3: [0, 1, 2], 9: [0, 1, 2],
        4: [0, 1, 2, 3], 5: [0, 1, 2, 3], 6: [0, 1, 2, 3], 7: [0, 1, 2, 3],
        8: [0, 1, 2, 3, 4], 10: [0, 1, 2, 3], 11: [0, 1, 2, 3], 12: [0, 1, 2, 3],
        13: [0, 1, 2, 3], 14: [0, 1, 2, 3], 15: [0, 1, 2, 3], 16: [0, 1, 2, 3],
        17: [0, 1, 2, 3], 18: [0, 1, 2, 3, 4]
    }

    count = 0
    for _id, index_list in data.items():
        for i in index_list:
            if count == 3:
                return
            for _ in range(3):
                await d.get(f"cmd=formation&type=4&formationid={_id}&attrindex={i}&times=1")
                if "助阵组合所需佣兵不满足条件" in d.html:
                    return
                if "阅历不足" in d.html:
                    return
                if "提升成功" in d.html:
                    count += 1
                    d.log(f"✅ 助阵提升成功 ({count}/3)")
                    break
                if "经验值已经达到最大" in d.html or "你还没有激活该属性" in d.html:
                    break


async def 查看好友资料(d: DaLeDou):
    await d.get("cmd=view&type=6")
    if "开启查看好友信息和收徒" in d.html:
        await d.get("cmd=set&type=1")
    await d.get("cmd=friendlist&page=2")
    for uin in d.findall(r"</a>\d+.*?B_UID=(\d+)"):
        await d.get(f"cmd=totalinfo&B_UID={uin}")


async def 兵法研习(d: DaLeDou):
    for _id in [21001, 2570, 21032, 2544]:
        await d.get(f"cmd=brofight&subtype=12&op=practice&baseid={_id}")
        if "研习成功" in d.html:
            d.log(f"✅ 兵法研习成功")
            break


async def 挑战陌生人(d: DaLeDou):
    await d.get("cmd=friendlist&type=1")
    for u in d.findall(r"</a>\d+.*?B_UID=(\d+)")[:4]:
        await d.get(f"cmd=fight&B_UID={u}&page=1&type=9")
        if "体力值不足" in d.html:
            break


async def 强化神装(d: DaLeDou):
    try:
        upgrade_list = d.config("神装.magic_outfit_ids")
    except:
        return

    if not upgrade_list:
        return

    for outfit_id in upgrade_list:
        await d.get(f"cmd=outfit&op=1&magic_outfit_id={outfit_id}")


async def 徽章进阶(d: DaLeDou):
    badge_id_str = d.config("徽章馆.badge_id")
    if badge_id_str is None:
        d.log("⚠️ 未配置徽章ID")
        return
    
    try:
        badge_id = int(badge_id_str)
    except (ValueError, TypeError):
        d.log(f"⚠️ 徽章ID配置错误: {badge_id_str}")
        return
    
    await d.get(f"cmd=achievement&op=upgradelevel&achievement_id={badge_id}&times=1")
    result = d.find()
    
    if "徽章已达到最高等级" in d.html:
        d.log("📌 徽章已达最高等级")
    elif "进阶成功" in d.html:
        d.log(f"✅ 徽章进阶成功: {result}")
    elif "材料不足" in d.html:
        d.log("⚠️ 徽章进阶材料不足")


async def 武器专精(d: DaLeDou):
    type_id = d.config("武器专精.type_id")
    
    if type_id is None:
        d.log("⚠️ 未配置 type_id")
        return
    
    try:
        type_id = int(type_id)
    except (ValueError, TypeError):
        d.log(f"⚠️ type_id 配置错误: {type_id}")
        return
    
    try:
        ten_times = d.config("武器专精.ten_times", False)
    except:
        ten_times = False
    
    op = 3 if ten_times else 2
    await d.get(f"cmd=weapon_specialize&op={op}&type_id={type_id}")
    result = d.find()
    
    if "材料不足" in d.html:
        d.log("⚠️ 武器专精材料不足")
    elif "成功" in d.html:
        d.log(f"✅ 武器专精成功: {result}")


# ==================== 任务映射表 ====================

TASK_HANDLERS = {
    "增强经脉": 增强经脉,
    "助阵": 助阵,
    "查看好友资料": 查看好友资料,
    "兵法研习": 兵法研习,
    "挑战陌生人": 挑战陌生人,
    "强化神装": 强化神装,
    "徽章进阶": 徽章进阶,
    "武器专精": 武器专精,
}


# ==================== 主任务执行函数 ====================

async def run_daily_tasks(d: DaLeDou):
    """
    执行日常任务
    """
    tasks = await get_task_list(d)
    
    if not tasks:
        d.log("未检测到任务")
        return
    
    d.log(f"检测到 {len(tasks)} 个任务")
    
    for task in tasks:
        if task["status"] == "已完成":
            d.log(f"✅ {task['name']} -> 已完成")
        else:
            d.log(f"🔄 {task['name']} -> 未完成")
    
    completed = sum(1 for t in tasks if t["status"] == "已完成")
    d.log(f"已完成 {completed}/{len(tasks)}")
    
    for task in tasks:
        if task["status"] == "已完成":
            continue
        
        task_id = task["id"]
        task_name = task["name"]
        
        handler = None
        for key, func in TASK_HANDLERS.items():
            if key in task_name or task_name in key:
                handler = func
                break
        
        if handler is None:
            continue
        
        try:
            await handler(d)
            
            # 重试检测任务状态
            for retry in range(3):
                await asyncio.sleep(1.0)
                status = await get_task_status_from_detail(d, task_id)
                if status == "已完成":
                    d.log(f"✅ {task_name} -> 已完成")
                    break
                elif retry == 2:
                    d.log(f"⚠️ {task_name} -> 未完成")
                
        except Exception as e:
            d.log(f"❌ {task_name} -> {e}")
        
        await asyncio.sleep(0.3)
    
    # 一键完成任务（领取奖励）
    await d.get("cmd=task&sub=7")