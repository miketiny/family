<?php
header('Content-Type: text/html; charset=utf-8');

$listFile = __DIR__ . '/zupu_list.json';

// 获取当前访问的族谱ID
$treeId = isset($_GET['id']) ? trim($_GET['id']) : '';

// ========== POST 请求处理 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['action'])) {
        echo json_encode(array('success' => false, 'error' => '无效请求'));
        exit;
    }

    $action = $data['action'];

    // ----- 创建新族谱 -----
    if ($action === 'create') {
        $surname = isset($data['surname']) ? trim($data['surname']) : '';
        $password = isset($data['password']) ? trim($data['password']) : '';

        if (mb_strlen($surname, 'UTF-8') < 1) {
            echo json_encode(array('success' => false, 'error' => '请输入姓氏'));
            exit;
        }
        if (!preg_match('/^\d{4}$/', $password)) {
            echo json_encode(array('success' => false, 'error' => '请输入4位数字密码'));
            exit;
        }

        // 加载列表
        $zupuList = array();
        if (file_exists($listFile)) {
            $content = file_get_contents($listFile);
            $decoded = json_decode($content, true);
            if ($decoded) $zupuList = $decoded;
        }

        // 生成唯一ID（8位小写字母+数字）
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $newId = '';
            for ($i = 0; $i < 8; $i++) {
                $newId .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
            $exists = false;
            foreach ($zupuList as $item) {
                if ($item['id'] === $newId) { $exists = true; break; }
            }
        } while ($exists);

        // 创建初始树数据
        $jsonFile = __DIR__ . '/data_' . $newId . '.json';
        $initialTree = array(
            'title' => $surname . '氏家族脉系图',
            'subtitle' => '参天之木，必有其根；怀山之水，必有其源',
            'version' => 1,
            'root' => array(
                'id' => 'root',
                'name' => $surname . '氏高祖',
                'spouse' => '',
                'born' => '',
                'info' => '',
                'children' => array()
            )
        );
        file_put_contents($jsonFile, json_encode($initialTree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // 添加到列表
        $zupuList[] = array(
            'id' => $newId,
            'surname' => $surname,
            'password' => $password,
            'createdAt' => date('Y-m-d H:i:s')
        );
        file_put_contents($listFile, json_encode($zupuList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        echo json_encode(array('success' => true, 'id' => $newId));
        exit;
    }

    // ----- 验证密码（从列表页点击进入） -----
    if ($action === 'verifyPassword') {
        $vid = isset($data['id']) ? trim($data['id']) : '';
        $pwd = isset($data['password']) ? trim($data['password']) : '';

        if (!file_exists($listFile)) {
            echo json_encode(array('success' => false, 'error' => '无族谱数据'));
            exit;
        }
        $zupuList = json_decode(file_get_contents($listFile), true);
        if (!$zupuList) {
            echo json_encode(array('success' => false, 'error' => '无族谱数据'));
            exit;
        }

        foreach ($zupuList as $item) {
            if ($item['id'] === $vid) {
                if ($item['password'] === $pwd) {
                    echo json_encode(array('success' => true));
                } else {
                    echo json_encode(array('success' => false, 'error' => '密码错误'));
                }
                exit;
            }
        }
        echo json_encode(array('success' => false, 'error' => '未找到该族谱'));
        exit;
    }

    // ----- 以下操作需要 treeId -----
    $vid = isset($data['treeId']) ? trim($data['treeId']) : '';
    if (!$vid && $treeId) $vid = $treeId;
    if (!$vid) {
        echo json_encode(array('success' => false, 'error' => '缺少族谱ID'));
        exit;
    }

    $jsonFile = __DIR__ . '/data_' . $vid . '.json';
    $versionDir = __DIR__ . '/data_' . $vid;
    $blessFile = __DIR__ . '/blessing_' . $vid . '.json';

    // 获取族谱密码
    function getTreePassword($listFile, $vid) {
        if (!file_exists($listFile)) return '';
        $list = json_decode(file_get_contents($listFile), true);
        if (!$list) return '';
        foreach ($list as $item) {
            if ($item['id'] === $vid) return $item['password'];
        }
        return '';
    }

    // ----- 保存族谱 -----
    if ($action === 'save' && isset($data['tree'])) {
        $fileData = array('version' => 1, 'root' => null);
        if (file_exists($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $decoded = json_decode($content, true);
            if ($decoded) $fileData = $decoded;
        }
        if (!isset($fileData['version'])) $fileData['version'] = 1;

        $clientVersion = isset($data['version']) ? intval($data['version']) : 0;
        $serverVersion = intval($fileData['version']);

        if ($clientVersion !== $serverVersion && $clientVersion !== 0) {
            echo json_encode(array(
                'success' => false,
                'conflict' => true,
                'tree' => isset($fileData['root']) ? $fileData['root'] : null,
                'version' => $serverVersion
            ));
            exit;
        }

        $newVersion = $serverVersion + 1;
        $surname = '';
        $list = json_decode(file_get_contents($listFile), true);
        if ($list) {
            foreach ($list as $item) {
                if ($item['id'] === $vid) { $surname = $item['surname']; break; }
            }
        }

        $saveData = array(
            'title' => $surname . '氏家族脉系图',
            'subtitle' => '参天之木，必有其根；怀山之水，必有其源',
            'version' => $newVersion,
            'root' => $data['tree']
        );
        $result = file_put_contents($jsonFile, json_encode($saveData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(array('success' => $result !== false, 'version' => $newVersion));
        exit;
    }

    // ----- 保存历史版本 -----
    if ($action === 'saveVersion' && isset($data['password']) && isset($data['tree'])) {
        $treePwd = getTreePassword($listFile, $vid);
        if ($data['password'] !== $treePwd) {
            echo json_encode(array('success' => false, 'error' => '密码错误'));
            exit;
        }
        if (!file_exists($versionDir)) {
            mkdir($versionDir, 0777, true);
        }
        $existingFiles = glob($versionDir . '/v*.json');
        $maxV = 0;
        foreach ($existingFiles as $f) {
            $bn = basename($f, '.json');
            if (preg_match('/^v(\d+)$/', $bn, $m)) {
                $maxV = max($maxV, intval($m[1]));
            }
        }
        $newV = $maxV + 1;
        $versionFileName = $versionDir . '/v' . $newV . '.json';
        $surname = '';
        $list = json_decode(file_get_contents($listFile), true);
        if ($list) {
            foreach ($list as $item) {
                if ($item['id'] === $vid) { $surname = $item['surname']; break; }
            }
        }
        $versionData = array(
            'title' => $surname . '氏家族脉系图',
            'subtitle' => '参天之木，必有其根；怀山之水，必有其源',
            'version' => $newV,
            'savedAt' => date('Y-m-d H:i:s'),
            'root' => $data['tree']
        );
        $result = file_put_contents($versionFileName, json_encode($versionData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(array('success' => $result !== false, 'version' => $newV, 'savedAt' => $versionData['savedAt']));
        exit;
    }

    // ----- 列出历史版本 -----
    if ($action === 'listVersions') {
        $versions = array();
        if (file_exists($versionDir)) {
            $files = glob($versionDir . '/v*.json');
            foreach ($files as $f) {
                $bn = basename($f, '.json');
                if (preg_match('/^v(\d+)$/', $bn, $m)) {
                    $content = file_get_contents($f);
                    $j = json_decode($content, true);
                    $savedAt = isset($j['savedAt']) ? $j['savedAt'] : date('Y-m-d H:i:s', filemtime($f));
                    $versions[] = array(
                        'file' => $bn . '.json',
                        'version' => intval($m[1]),
                        'savedAt' => $savedAt
                    );
                }
            }
        }
        usort($versions, function($a, $b) { return $b['version'] - $a['version']; });
        echo json_encode(array('success' => true, 'versions' => $versions));
        exit;
    }

    // ----- 加载祈福数据 -----
    if ($action === 'loadBlessings') {
        $blessData = array('totalCount' => 0, 'messages' => array());
        if (file_exists($blessFile)) {
            $content = file_get_contents($blessFile);
            $decoded = json_decode($content, true);
            if ($decoded) $blessData = $decoded;
        }
        echo json_encode(array('success' => true, 'data' => $blessData));
        exit;
    }

    // ----- 添加祈福 -----
    if ($action === 'addBlessing') {
        $blessData = array('totalCount' => 0, 'messages' => array());
        if (file_exists($blessFile)) {
            $content = file_get_contents($blessFile);
            $decoded = json_decode($content, true);
            if ($decoded) $blessData = $decoded;
        }
        $blessData['totalCount'] = intval($blessData['totalCount']) + 1;
        if (isset($data['message']) && trim($data['message']) !== '') {
            $msg = mb_substr(trim($data['message']), 0, 300, 'UTF-8');
            $blessData['messages'][] = array(
                'id' => count($blessData['messages']) + 1,
                'text' => $msg,
                'time' => date('Y-m-d H:i:s')
            );
        }
        file_put_contents($blessFile, json_encode($blessData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(array('success' => true, 'totalCount' => $blessData['totalCount'], 'data' => $blessData));
        exit;
    }

    // ----- 加载版本详情 -----
    if ($action === 'loadVersion' && isset($data['file'])) {
        $filePath = $versionDir . '/' . basename($data['file']);
        if (file_exists($filePath) && preg_match('/^v\d+\.json$/', basename($data['file']))) {
            $content = file_get_contents($filePath);
            $j = json_decode($content, true);
            echo json_encode(array('success' => true, 'data' => $j));
        } else {
            echo json_encode(array('success' => false, 'error' => '版本文件不存在'));
        }
        exit;
    }

    echo json_encode(array('success' => false, 'error' => '无效请求'));
    exit;
}

// ========== 列表页模式（无 id 参数） ==========
if ($treeId === '') {
    $zupuList = array();
    if (file_exists($listFile)) {
        $content = file_get_contents($listFile);
        $decoded = json_decode($content, true);
        if ($decoded) $zupuList = $decoded;
    }
    // 按创建时间升序
    usort($zupuList, function($a, $b) {
        return strcmp($a['createdAt'], $b['createdAt']);
    });

    function relativeTime($datetime) {
        $ts = strtotime($datetime);
        $diff = time() - $ts;
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . '分钟前';
        if ($diff < 86400) return floor($diff / 3600) . '小时前';
        if ($diff < 604800) return floor($diff / 86400) . '天前';
        if ($diff < 2592000) return floor($diff / 604800) . '周前';
        if ($diff < 31536000) return floor($diff / 2592000) . '个月前';
        return floor($diff / 31536000) . '年前';
    }
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
<title>族谱名录</title>
<link rel="icon" href="data:image/svg+xml,<?php echo rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'32\' height=\'32\' viewBox=\'0 0 32 32\'><circle cx=\'16\' cy=\'16\' r=\'15\' fill=\'#8B0000\' stroke=\'#DAA520\' stroke-width=\'2\'/><text x=\'16\' y=\'22\' text-anchor=\'middle\' font-size=\'20\' font-weight=\'bold\' fill=\'#FFD700\' font-family=\'serif\'>族</text></svg>'); ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:"STSong","SimSun","Noto Serif CJK SC","KaiTi","STKaiti","楷体",serif;
    background:linear-gradient(180deg,#FDF6EC 0%,#F5E6D3 50%,#EDDCC8 100%);
    min-height:100vh;color:#3E2723;background-attachment:fixed;
}
/* ---- LIST PAGE ---- */
.list-page{padding:0 16px 40px}
.list-header{text-align:center;padding:36px 16px 24px}
.list-header .main-title{
    font-size:clamp(24px,5vw,40px);color:#8B0000;letter-spacing:6px;font-weight:bold;
    text-shadow:1px 1px 2px rgba(0,0,0,0.08);display:inline-block;
}
.list-header .main-title::before,.list-header .main-title::after{
    content:'◆';color:#DAA520;font-size:12px;vertical-align:middle;margin:0 10px;
}
.list-header .sub-title{font-size:clamp(12px,2vw,15px);color:#8B6914;letter-spacing:3px;margin-top:10px;font-style:italic}
.list-header .divider{width:100px;height:2px;background:linear-gradient(90deg,transparent,#DAA520,transparent);margin:14px auto 0}

.create-btn-wrap{text-align:center;margin:8px 0 28px}
.create-btn{
    display:inline-block;padding:14px 40px;
    background:linear-gradient(135deg,#8B0000,#C62828);
    color:#FFD700;font-size:18px;font-weight:bold;letter-spacing:4px;
    border:2px solid #DAA520;border-radius:12px;
    cursor:pointer;font-family:inherit;
    box-shadow:0 4px 16px rgba(139,0,0,0.3);
    transition:all .3s;
    text-decoration:none;
}
.create-btn:hover{transform:translateY(-2px);box-shadow:0 6px 22px rgba(139,0,0,0.45)}

.list-wrap{max-width:680px;margin:0 auto;padding:0 8px;
    display:grid;grid-template-columns:repeat(4,1fr) 14px repeat(4,1fr);
    row-gap:10px;column-gap:6px;
}
.list-card:nth-child(8n+5){grid-column:6}
.list-card:nth-child(8n+6){grid-column:7}
.list-card:nth-child(8n+7){grid-column:8}
.list-card:nth-child(8n+8){grid-column:9}
.list-card{
    aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    background:linear-gradient(180deg,#FFF8F0,#FDF0E0);border:2px solid #C4956A;border-radius:10px;
    cursor:pointer;transition:all .3s;box-shadow:0 1px 4px rgba(100,50,20,0.08);
    padding:4px 2px;position:relative;overflow:hidden;
}
.list-card:hover{border-color:#8B0000;transform:translateY(-2px);box-shadow:0 3px 10px rgba(100,50,20,0.15)}
.list-card .card-surname{color:#8B0000;font-weight:bold;line-height:1;text-align:center;width:100%}
.list-card .card-char-1{font-size:clamp(24px,6vw,46px)}
.list-card .card-char-2{font-size:clamp(24px,6vw,46px);display:inline-block;transform:scaleX(0.5);white-space:nowrap}
.list-card .card-time{font-size:9px;color:#B0A090;margin-top:2px;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.list-card .card-demo-badge{
    position:absolute;top:0;right:0;z-index:1;
    background:#FFD700;color:#8B0000;font-size:10px;font-weight:bold;
    padding:2px 6px;border-radius:0 8px 0 8px;
    line-height:1.2;
}

.list-empty{text-align:center;color:#B0A090;padding:60px 20px;font-size:15px;letter-spacing:3px;grid-column:1/-1}

/* ---- MODAL ---- */
.modal{
    display:none;position:fixed;top:0;left:0;right:0;bottom:0;
    background:rgba(62,39,35,0.55);z-index:200;align-items:center;justify-content:center;
}
.modal.on{display:flex}
.modal-box{
    background:#FFF8F0;border:3px solid #DAA520;border-radius:12px;padding:24px 20px;
    width:92%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:popIn .25s ease;
}
@keyframes popIn{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}

.modal-box h3{text-align:center;color:#8B0000;font-size:18px;letter-spacing:3px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #DAA520}

.modal-box input{
    width:100%;padding:10px 12px;border:1.5px solid #C4956A;border-radius:5px;
    font-size:15px;font-family:inherit;background:#FFF;color:#3E2723;
    transition:border-color .3s;margin-bottom:12px;
}
.modal-box input:focus{outline:none;border-color:#8B0000;box-shadow:0 0 6px rgba(139,0,0,0.2)}

.modal-box .field-label{font-size:13px;color:#8B6914;letter-spacing:1px;display:block;margin-bottom:4px}

.modal-btns{display:flex;gap:10px;justify-content:center;margin-top:4px}
.modal-btns button{
    padding:10px 24px;border-radius:6px;font-size:14px;font-family:inherit;
    cursor:pointer;letter-spacing:2px;border:none;transition:all .3s;
}
.btn-ok{background:linear-gradient(180deg,#8B0000,#6B0000);color:#FFD700}
.btn-ok:hover{box-shadow:0 2px 10px rgba(139,0,0,0.3)}
.btn-no{background:#E0E0E0;color:#555}
.btn-no:hover{background:#CCC}

.toast{
    position:fixed;top:16px;left:50%;transform:translateX(-50%);
    background:#3E2723;color:#FFD700;padding:8px 22px;border-radius:6px;
    font-size:13px;letter-spacing:2px;z-index:300;opacity:0;transition:opacity .3s;
    pointer-events:none;box-shadow:0 3px 10px rgba(0,0,0,0.3);
}
.toast.on{opacity:1}

.list-footer{text-align:center;padding:30px 20px;color:#8B6914;font-size:12px;letter-spacing:2px}

.source-btn{
    padding:10px 28px;font-size:14px;letter-spacing:3px;font-family:inherit;
    background:linear-gradient(180deg,#FFF8F0,#FDF0E0);color:#8B6914;
    border:2px solid #C4956A;border-radius:8px;cursor:pointer;transition:all .3s;
}
.source-btn:hover{border-color:#8B0000;color:#8B0000}

@media(max-width:480px){
    .list-wrap{grid-template-columns:repeat(2,1fr) 14px repeat(2,1fr);row-gap:8px;column-gap:5px}
    .list-card:nth-child(8n+5),.list-card:nth-child(8n+6),
    .list-card:nth-child(8n+7),.list-card:nth-child(8n+8){grid-column:auto}
    .list-card:nth-child(4n+3){grid-column:4}
    .list-card:nth-child(4n+4){grid-column:5}
    .create-btn{padding:12px 30px;font-size:16px;letter-spacing:3px}
}
</style>
</head>
<body>

<div class="list-page">
    <div class="list-header">
        <div class="main-title">族 谱 名 录</div>
        <div class="sub-title">参天之木，必有其根；怀山之水，必有其源</div>
        <div class="divider"></div>
    </div>

    <div class="create-btn-wrap">
        <button class="create-btn" onclick="openCreate()">创建我的族谱</button>
    </div>

    <div class="list-wrap" id="listWrap">
        <?php if (empty($zupuList)): ?>
        <div class="list-empty">暂无族谱，点击上方按钮创建第一个族谱</div>
        <?php else: ?>
        <?php $index = 0; foreach ($zupuList as $item): 
            $sLen = mb_strlen($item['surname'], 'UTF-8');
            $charClass = $sLen > 1 ? 'card-char-2' : 'card-char-1';
            $onclick = ($index < 2)
                ? "openDemo('".htmlspecialchars($item['id'])."','".htmlspecialchars($item['surname'])."')"
                : "openPassword('".htmlspecialchars($item['id'])."','".htmlspecialchars($item['surname'])."')";
            $index++;
        ?>
        <div class="list-card" onclick="<?php echo $onclick; ?>">
            <?php if ($index <= 2): ?><span class="card-demo-badge">示例</span><?php endif; ?>
            <span class="card-surname <?php echo $charClass; ?>"><?php echo htmlspecialchars($item['surname']); ?></span>
            <span class="card-time"><?php echo relativeTime($item['createdAt']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="text-align:center;margin:20px 0 12px">
        <button class="source-btn" onclick="openSource()">源码下载</button>
    </div>

    <div class="list-footer">诚心祈福，福泽绵长</div>
</div>

<!-- 创建族谱弹窗 -->
<div class="modal" id="createModal">
    <div class="modal-box">
        <h3>创建我的族谱</h3>
        <label class="field-label">姓氏（如：张、李、王）</label>
        <input type="text" id="createSurname" placeholder="请输入姓氏" maxlength="4">
        <label class="field-label">设置密码（4位数字）</label>
        <input type="password" id="createPassword" placeholder="请设置4位数字密码" maxlength="4" inputmode="numeric" pattern="\d{4}">
        <div class="modal-btns">
            <button class="btn-no" onclick="closeCreate()">取 消</button>
            <button class="btn-ok" onclick="confirmCreate()">确认创建</button>
        </div>
    </div>
</div>

<!-- 示例提示弹窗 -->
<div class="modal" id="demoModal">
    <div class="modal-box">
        <h3 id="demoTitle">示例族谱</h3>
        <p style="text-align:center;color:#5D4037;margin:12px 0;line-height:1.8;font-size:14px">
            这是示例族谱，密码为 <b style="color:#8B0000;letter-spacing:2px">1234</b>
        </p>
        <input type="password" id="demoPwd" placeholder="请输入密码：1234" maxlength="4" inputmode="numeric" pattern="\d{4}">
        <div class="modal-btns">
            <button class="btn-no" onclick="closeDemo()">取 消</button>
            <button class="btn-ok" onclick="confirmDemo()">确 认</button>
        </div>
    </div>
</div>

<!-- 源码下载弹窗 -->
<div class="modal" id="sourceModal">
    <div class="modal-box" style="max-width:440px">
        <h3>获取源码</h3>
        <ol style="color:#5D4037;font-size:13px;line-height:1.9;padding-left:20px;margin:12px 0">
            <li>联系站长 <b style="color:#8B0000">免费</b> 获取源码</li>
            <li>部署到自己的服务器，即可以创建 <b>完全私密</b> 的、仅对自己家族人查看和使用的电子家谱（该系统市场价曾是10万元起步的哟）</li>
            <li>服务器部署需要购买诸如阿里云、华为云、腾讯云云主机，申请域名，备案，最低需要<b>200元/年</b></li>
            <li>你也可以使用站长提供的开放功能，但是数据加密能力有限，如果你的密码过于简单被他人猜到，涉及了你不愿公开的数据被公开，<b>站长不负责</b>哟</li>
            <li>当然站长很忙，没闲心去查看你的数据，各位也遵守网络公约，<b>不要试图查看别人的私密数据</b></li>
        </ol>
        <div class="modal-btns">
            <button class="btn-no" onclick="closeSource()">关 闭</button>
        </div>
    </div>
</div>

<!-- 密码验证弹窗 -->
<div class="modal" id="pwdModal">
    <div class="modal-box">
        <h3 id="pwdTitle">请输入密码</h3>
        <input type="password" id="pwdInput" placeholder="请输入4位数字密码" maxlength="4" inputmode="numeric" pattern="\d{4}">
        <div class="modal-btns">
            <button class="btn-no" onclick="closePwd()">取 消</button>
            <button class="btn-ok" onclick="confirmPwd()">确 认</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
var pwdTargetId = '';
var pwdTargetSurname = '';
var demoId = '';

function toast(m){
    var t=document.getElementById('toast');t.textContent=m;t.classList.add('on');
    clearTimeout(t._t);t._t=setTimeout(function(){t.classList.remove('on')},3000);
}

// ---- 示例族谱 ----
function openDemo(id,surname){
    demoId=id;
    document.getElementById('demoTitle').textContent='示例族谱 — '+surname+'氏';
    document.getElementById('demoPwd').value='';
    document.getElementById('demoModal').classList.add('on');
    setTimeout(function(){document.getElementById('demoPwd').focus()},200);
}

function closeDemo(){demoId='';document.getElementById('demoModal').classList.remove('on')}

function confirmDemo(){
    var pwd=document.getElementById('demoPwd').value.trim();
    if(pwd==='1234'){
        window.location.href='?id='+demoId;
    }else{
        toast('密码错误，示例密码为 1234');
    }
}

// ---- 源码下载 ----
function openSource(){document.getElementById('sourceModal').classList.add('on')}
function closeSource(){document.getElementById('sourceModal').classList.remove('on')}

// ---- 创建族谱 ----
function openCreate(){
    document.getElementById('createSurname').value='';
    document.getElementById('createPassword').value='';
    document.getElementById('createModal').classList.add('on');
    setTimeout(function(){document.getElementById('createSurname').focus()},200);
}

function closeCreate(){
    document.getElementById('createModal').classList.remove('on');
}

function confirmCreate(){
    var surname=document.getElementById('createSurname').value.trim();
    var password=document.getElementById('createPassword').value.trim();
    if(!surname){toast('请输入姓氏');return}
    if(!/^\d{4}$/.test(password)){toast('请输入4位数字密码');return}

    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            window.location.href='?id='+r.id;
        }else{
            toast(r.error||'创建失败');
        }
    };
    x.send(JSON.stringify({action:'create',surname:surname,password:password}));
}

// ---- 密码验证 ----
function openPassword(id,surname){
    pwdTargetId=id;
    pwdTargetSurname=surname;
    document.getElementById('pwdTitle').textContent='请输入「'+surname+'」氏族谱密码';
    document.getElementById('pwdInput').value='';
    document.getElementById('pwdModal').classList.add('on');
    setTimeout(function(){document.getElementById('pwdInput').focus()},200);
}

function closePwd(){
    pwdTargetId='';
    document.getElementById('pwdModal').classList.remove('on');
}

function confirmPwd(){
    var pwd=document.getElementById('pwdInput').value.trim();
    if(!/^\d{4}$/.test(pwd)){toast('请输入4位数字密码');return}

    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            window.location.href='?id='+pwdTargetId;
        }else{
            toast(r.error||'密码错误');
        }
    };
    x.send(JSON.stringify({action:'verifyPassword',id:pwdTargetId,password:pwd}));
}

// ---- 键盘事件 ----
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){closeCreate();closePwd();closeDemo();closeSource()}
    if(e.key==='Enter'&&document.getElementById('createModal').classList.contains('on'))confirmCreate();
    if(e.key==='Enter'&&document.getElementById('pwdModal').classList.contains('on'))confirmPwd();
    if(e.key==='Enter'&&document.getElementById('demoModal').classList.contains('on'))confirmDemo();
});

// ---- 点击弹窗外部关闭 ----
document.getElementById('createModal').addEventListener('click',function(e){if(e.target===this)closeCreate()});
document.getElementById('pwdModal').addEventListener('click',function(e){if(e.target===this)closePwd()});
document.getElementById('demoModal').addEventListener('click',function(e){if(e.target===this)closeDemo()});
document.getElementById('sourceModal').addEventListener('click',function(e){if(e.target===this)closeSource()});

// 限制密码输入框只能输入数字
document.getElementById('createPassword').addEventListener('input',function(){
    this.value=this.value.replace(/\D/g,'').slice(0,4);
});
document.getElementById('pwdInput').addEventListener('input',function(){
    this.value=this.value.replace(/\D/g,'').slice(0,4);
});
document.getElementById('demoPwd').addEventListener('input',function(){
    this.value=this.value.replace(/\D/g,'').slice(0,4);
});
</script>

</body>
</html>
<?php
    exit;
}

// ========== 族谱编辑页模式（有 id 参数） ==========

// 验证族谱是否存在
if (!file_exists($listFile)) {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>族谱不存在</title></head><body style="text-align:center;padding:60px;font-family:serif"><h2 style="color:#8B0000">该族谱不存在</h2><p><a href="index.php">返回族谱列表</a></p></body></html>';
    exit;
}

$zupuList = json_decode(file_get_contents($listFile), true);
$treeInfo = null;
foreach ($zupuList as $item) {
    if ($item['id'] === $treeId) {
        $treeInfo = $item;
        break;
    }
}
if (!$treeInfo) {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>族谱不存在</title></head><body style="text-align:center;padding:60px;font-family:serif"><h2 style="color:#8B0000">该族谱不存在</h2><p><a href="index.php">返回族谱列表</a></p></body></html>';
    exit;
}

$surname = $treeInfo['surname'];
$appPassword = $treeInfo['password'];
$jsonFile = __DIR__ . '/data_' . $treeId . '.json';
$versionDir = __DIR__ . '/data_' . $treeId;
$blessFile = __DIR__ . '/blessing_' . $treeId . '.json';

// 加载树数据
$treeData = array('version' => 1, 'root' => null);
if (file_exists($jsonFile)) {
    $content = file_get_contents($jsonFile);
    $decoded = json_decode($content, true);
    if ($decoded && isset($decoded['root'])) {
        $treeData = $decoded;
    }
}
if ($treeData['root'] === null) {
    $treeData['root'] = array(
        'id' => 'root',
        'name' => $surname . '氏高祖',
        'spouse' => '',
        'born' => '',
        'info' => '',
        'children' => array()
    );
}
$fileVersion = isset($treeData['version']) ? intval($treeData['version']) : 1;
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars($surname); ?>氏家族脉系图</title>
<link rel="icon" href="data:image/svg+xml,<?php echo rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'32\' height=\'32\' viewBox=\'0 0 32 32\'><circle cx=\'16\' cy=\'16\' r=\'15\' fill=\'#8B0000\' stroke=\'#DAA520\' stroke-width=\'2\'/><text x=\'16\' y=\'22\' text-anchor=\'middle\' font-size=\'20\' font-weight=\'bold\' fill=\'#FFD700\' font-family=\'serif\'>'.$surname.'</text></svg>'); ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:"STSong","SimSun","Noto Serif CJK SC","KaiTi","STKaiti","楷体",serif;
    background:linear-gradient(180deg,#FDF6EC 0%,#F5E6D3 50%,#EDDCC8 100%);
    min-height:100vh;color:#3E2723;background-attachment:fixed;
}

.header-section{text-align:center;padding:32px 16px 6px}
.header-section .main-title{
    font-size:clamp(24px,5vw,40px);color:#8B0000;letter-spacing:6px;font-weight:bold;
    text-shadow:1px 1px 2px rgba(0,0,0,0.08);display:inline-block;
}
.header-section .main-title::before,.header-section .main-title::after{
    content:'◆';color:#DAA520;font-size:12px;vertical-align:middle;margin:0 10px;
}
.header-section .sub-title{font-size:clamp(12px,2vw,15px);color:#8B6914;letter-spacing:3px;margin-top:10px;font-style:italic}
.header-section .divider{width:100px;height:2px;background:linear-gradient(90deg,transparent,#DAA520,transparent);margin:14px auto 0}

.main-wrap{position:relative;z-index:1;padding:10px 50px 60px 24px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.main-wrap.list-mode{padding:10px 0 60px 0;overflow-x:hidden}
.tree-wrap{display:flex;justify-content:center;padding:10px 80px 10px 0;min-width:max-content}

/* ---- TREE NODE ---- */
.tnode{display:flex;flex-direction:column;align-items:center}

.ncard{
    background:linear-gradient(180deg,#FFF8F0,#FDF0E0);
    border:2px solid #C4956A;border-radius:8px;padding:10px 16px;min-width:80px;
    text-align:center;box-shadow:0 2px 6px rgba(100,50,20,0.1);
    transition:border-color .3s;
    user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;touch-action:manipulation;
}
.ncard.root-card{
    background:linear-gradient(180deg,#FFF0E0,#FFE8D0);border:3px solid #DAA520;
    border-radius:10px;padding:14px 24px;min-width:100px;box-shadow:0 3px 10px rgba(180,130,50,0.18);
    position:relative;
}
.ncard:hover{border-color:#8B4513}

.root-bless-badge{
    position:absolute;top:50%;left:100%;margin-left:6px;
    background:linear-gradient(135deg,#C62828,#8B0000);
    color:#FFD700;font-size:11px;font-weight:bold;letter-spacing:2px;
    padding:4px 10px;border-radius:12px;white-space:nowrap;
    box-shadow:0 2px 8px rgba(139,0,0,0.35);
    animation:badgeBounce 1.5s ease-in-out infinite;
    z-index:5;cursor:pointer;
}
@keyframes badgeBounce{
    0%,100%{transform:translateY(-50%)}
    50%{transform:translateY(calc(-50% - 6px))}
}

.nname-row{display:inline-flex;align-items:center;gap:3px}
.nname{
    font-size:clamp(14px,2.5vw,18px);color:#8B0000;font-weight:bold;letter-spacing:2px;
    cursor:text;padding:2px 4px;border-radius:3px;transition:background .2s;
}
.root-card .nname{font-size:clamp(18px,3vw,22px);color:#6B0000}
.nname:hover{background:rgba(139,0,0,0.06)}
.nname-input{
    font-size:clamp(14px,2.5vw,18px);color:#8B0000;font-weight:bold;letter-spacing:2px;
    border:1.5px solid #8B0000;border-radius:3px;padding:2px 4px;width:100px;text-align:center;
    font-family:inherit;background:#fff;outline:none;
}
.root-card .nname-input{font-size:clamp(18px,3vw,22px);color:#6B0000;width:130px}

.nplus{
    display:none;padding:2px 8px;border-radius:4px;border:1.5px solid #2E7D32;
    background:#E8F5E9;color:#2E7D32;font-size:12px;line-height:1.4;
    cursor:pointer;flex-shrink:0;transition:all .2s;
    font-family:inherit;letter-spacing:1px;white-space:nowrap;
}
.nplus.show{display:inline-block}
.nplus:hover{background:#2E7D32;color:#fff;box-shadow:0 2px 6px rgba(0,0,0,0.15)}

.nspouse{font-size:clamp(11px,1.5vw,13px);color:#8B6914;margin-top:3px}
.ninfo{font-size:11px;color:#999;margin-top:1px}

/* ---- TREE LINES ---- */
.nvline{width:3px;height:30px;background:#8B4513;margin:0 auto;flex-shrink:0}
.nchildren{display:flex;align-items:flex-start;justify-content:center;gap:12px}
.nchild{
    display:flex;flex-direction:column;align-items:center;
    padding:30px 0 0;position:relative;flex:0 0 auto;
}
.nchild::before{
    content:'';position:absolute;top:0;left:-6px;right:-6px;height:3px;background:#8B4513;
}
.nchild::after{
    content:'';position:absolute;top:0;left:50%;width:3px;height:30px;
    background:#8B4513;margin-left:-1.5px;
}
.nchild:first-child::before{left:50%}
.nchild:last-child::before{right:50%}

/* ---- MODAL ---- */
.modal{
    display:none;position:fixed;top:0;left:0;right:0;bottom:0;
    background:rgba(62,39,35,0.55);z-index:200;align-items:center;justify-content:center;
}
.modal.on{display:flex}
.modal-box{
    background:#FFF8F0;border:3px solid #DAA520;border-radius:12px;padding:24px 20px;
    width:92%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:popIn .25s ease;
}
@keyframes popIn{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}

.modal-box h3{text-align:center;color:#8B0000;font-size:18px;letter-spacing:3px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #DAA520}
.modal-box .rel-btns{display:flex;gap:8px;justify-content:center;margin-bottom:16px;flex-wrap:wrap}
.rel-btn{
    flex:1;min-width:60px;padding:10px 6px;border:2px solid #C4956A;border-radius:8px;
    background:#FFF;color:#5D4037;font-size:14px;font-family:inherit;cursor:pointer;
    letter-spacing:1px;transition:all .2s;text-align:center;
}
.rel-btn:hover{border-color:#8B0000;background:#FFF5F5}
.rel-btn.sel{border-color:#8B0000;background:#8B0000;color:#FFD700;font-weight:bold}

.modal-box input{
    width:100%;padding:10px 12px;border:1.5px solid #C4956A;border-radius:5px;
    font-size:15px;font-family:inherit;background:#FFF;color:#3E2723;
    transition:border-color .3s;margin-bottom:16px;
}
.modal-box input:focus{outline:none;border-color:#8B0000;box-shadow:0 0 6px rgba(139,0,0,0.2)}

.modal-btns{display:flex;gap:10px;justify-content:center}
.modal-btns button{
    padding:9px 22px;border-radius:6px;font-size:14px;font-family:inherit;
    cursor:pointer;letter-spacing:2px;border:none;transition:all .3s;
}
.btn-ok{background:linear-gradient(180deg,#8B0000,#6B0000);color:#FFD700}
.btn-ok:hover{box-shadow:0 2px 10px rgba(139,0,0,0.3)}
.btn-no{background:#E0E0E0;color:#555}
.btn-no:hover{background:#CCC}

/* ---- TOAST ---- */
.toast{
    position:fixed;top:16px;left:50%;transform:translateX(-50%);
    background:#3E2723;color:#FFD700;padding:8px 22px;border-radius:6px;
    font-size:13px;letter-spacing:2px;z-index:300;opacity:0;transition:opacity .3s;
    pointer-events:none;box-shadow:0 3px 10px rgba(0,0,0,0.3);
}
.toast.on{opacity:1}

.footer-bar{text-align:center;padding:30px 20px;color:#8B6914;font-size:12px;letter-spacing:2px}

/* ---- VERSION BAR ---- */
.version-bar{
    text-align:center;padding:20px 24px 36px;color:#B0A090;
    font-size:12px;display:flex;align-items:center;justify-content:center;
    flex-wrap:wrap;gap:8px;
}
.vb-save{
    background:none;border:none;color:#B0A090;font-size:12px;
    cursor:pointer;font-family:inherit;letter-spacing:1px;
    padding:2px 4px;transition:color .2s;
}
.vb-save:hover{color:#8B6914}
.vb-sep{color:#D0C8B8;font-size:12px}
.vb-dates{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center}
.vb-empty{color:#C8C0B0;font-size:11px;letter-spacing:1px}
.vb-date-item{
    color:#B0A090;cursor:pointer;transition:color .2s;font-size:11px;
    letter-spacing:1px;white-space:nowrap;
}
.vb-date-item:hover{color:#8B6914;text-decoration:underline}

.modal-box-wide{width:95%;max-width:700px}
.view-ver-meta{
    text-align:center;color:#5D4037;font-size:13px;padding:6px 0 8px;
    line-height:1.6;
}
.view-ver-meta .ver-label{color:#8B6914;letter-spacing:1px}
.view-ver-meta .ver-value{color:#8B0000;font-weight:bold}
.view-ver-tree-wrap{
    max-height:55vh;overflow:auto;margin:0 -20px;padding:0 20px;
    border-top:1px solid rgba(218,165,32,0.3);border-bottom:1px solid rgba(218,165,32,0.3);
}
.view-ver-tree-wrap .ncard{cursor:default}
.view-ver-tree-wrap .ncard:hover{border-color:#C4956A}
.view-ver-tree-wrap .nname{cursor:default}
.view-ver-tree-wrap .nname:hover{background:transparent}

/* ---- BLESS MODAL ---- */
.bless-modal-box{
    background:linear-gradient(180deg,#FFF8F0,#FDF0E0);
    border:3px solid #DAA520;border-radius:16px;padding:30px 24px 24px;
    width:92%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,0.3);
    animation:popIn .25s ease;text-align:center;
}
.bless-title{
    font-size:22px;color:#8B0000;letter-spacing:4px;margin-bottom:6px;
    font-weight:bold;
}
.bless-subtitle{
    font-size:13px;color:#8B6914;letter-spacing:2px;margin-bottom:24px;
    font-style:italic;
}
.bless-btn-wrap{position:relative;display:inline-block;margin-bottom:20px}
.bless-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    width:160px;height:160px;border-radius:50%;
    background:radial-gradient(circle at 40% 35%,#FFD700,#E6A800,#B8860B);
    border:4px solid #DAA520;color:#8B0000;
    font-size:20px;font-weight:bold;letter-spacing:2px;
    cursor:pointer;font-family:inherit;
    box-shadow:0 0 20px rgba(218,165,32,0.5),0 6px 20px rgba(0,0,0,0.2);
    transition:all .15s ease;
    animation:blessPulse 2s ease-in-out infinite;
    user-select:none;-webkit-tap-highlight-color:transparent;
}
.bless-btn:active{
    transform:scale(0.9);
    box-shadow:0 0 40px rgba(255,215,0,0.8),0 3px 10px rgba(0,0,0,0.2);
    transition:all .08s ease;
}
.bless-btn .bless-emoji{font-size:40px;display:block;line-height:1}
.bless-btn .bless-label{font-size:16px;display:block;line-height:1}
@keyframes blessPulse{
    0%,100%{box-shadow:0 0 20px rgba(218,165,32,0.5),0 6px 20px rgba(0,0,0,0.2)}
    50%{box-shadow:0 0 40px rgba(255,215,0,0.8),0 8px 24px rgba(0,0,0,0.25)}
}

.bless-glow{
    position:absolute;top:-10px;left:-10px;right:-10px;bottom:-10px;
    border-radius:50%;background:transparent;
    box-shadow:0 0 30px rgba(255,215,0,0.4);
    animation:blessGlow 2s ease-in-out infinite;pointer-events:none;
}
@keyframes blessGlow{
    0%,100%{box-shadow:0 0 20px rgba(255,215,0,0.3)}
    50%{box-shadow:0 0 50px rgba(255,215,0,0.7)}
}

.bless-count{
    font-size:16px;color:#5D4037;letter-spacing:2px;margin-bottom:16px;
    padding:8px 16px;background:rgba(218,165,32,0.1);border-radius:20px;
    display:block;text-align:center;
}
.bless-count .count-num{color:#8B0000;font-weight:bold;font-size:24px}

.bless-msg-input{
    width:100%;padding:10px 12px;border:1.5px solid #C4956A;border-radius:8px;
    font-size:14px;font-family:inherit;background:#FFF;color:#3E2723;
    transition:border-color .3s;margin-bottom:8px;resize:none;height:60px;
    line-height:1.5;
}
.bless-msg-input:focus{outline:none;border-color:#8B0000;box-shadow:0 0 6px rgba(139,0,0,0.2)}
.bless-msg-hint{text-align:right;font-size:11px;color:#AAA;margin-bottom:12px}

.bless-scroll-wrap{
    overflow:hidden;position:relative;height:36px;
    border-top:1px solid rgba(218,165,32,0.3);
    border-bottom:1px solid rgba(218,165,32,0.3);
    padding:6px 0;margin-bottom:16px;
}
.bless-scroll-inner{
    display:flex;gap:40px;white-space:nowrap;
    animation:blessScroll linear infinite;
    width:max-content;
}
.bless-scroll-item{
    color:#8B6914;font-size:13px;letter-spacing:1px;flex-shrink:0;
}
@keyframes blessScroll{
    0%{transform:translateX(0)}
    100%{transform:translateX(-50%)}
}

/* ---- CONTEXT MENU ---- */
.ctx-menu{
    display:none;position:fixed;z-index:250;
    background:#FFF8F0;border:2px solid #DAA520;border-radius:10px;
    box-shadow:0 4px 18px rgba(0,0,0,0.3);overflow:hidden;
    min-width:130px;animation:popIn .15s ease;
}
.ctx-menu.on{display:block}
.ctx-menu-item{
    display:block;width:100%;padding:15px 24px;border:none;background:none;
    font-size:16px;font-family:inherit;color:#5D4037;cursor:pointer;
    text-align:center;letter-spacing:3px;border-bottom:1px solid rgba(218,165,32,0.2);
    transition:background .2s;-webkit-tap-highlight-color:transparent;
    user-select:none;
}
.ctx-menu-item:last-child{border-bottom:none}
.ctx-menu-item:hover,.ctx-menu-item:active{background:#FDF0E0}
.ctx-menu-item.ctx-del{color:#C62828;font-weight:bold}
.ctx-menu-item.ctx-del:hover,.ctx-menu-item.ctx-del:active{background:#FFEBEE}

/* ---- FU BADGE ---- */
.fu-badge{
    display:inline-block;font-size:15px;font-weight:bold;
    background:linear-gradient(135deg,#FFD700,#FFA000,#FFD700);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    background-clip:text;
    animation:fuShine 1.5s ease-in-out infinite;
    margin-left:1px;vertical-align:middle;line-height:1;
    filter:drop-shadow(0 0 4px rgba(255,215,0,0.7));
}
@keyframes fuShine{
    0%,100%{filter:drop-shadow(0 0 3px rgba(255,215,0,0.6)) brightness(1)}
    50%{filter:drop-shadow(0 0 10px rgba(255,215,0,1)) brightness(1.4)}
}
.root-card .fu-badge{font-size:18px}

/* ---- SETTINGS MODAL ---- */
.settings-fields{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}
.settings-fields label{font-size:13px;color:#8B6914;letter-spacing:1px;display:block;margin-bottom:2px}
.settings-fields input,.settings-fields textarea{
    width:100%;padding:10px 12px;border:1.5px solid #C4956A;border-radius:5px;
    font-size:14px;font-family:inherit;background:#FFF;color:#3E2723;
    transition:border-color .3s;resize:vertical;
}
.settings-fields input:focus,.settings-fields textarea:focus{
    outline:none;border-color:#8B0000;box-shadow:0 0 6px rgba(139,0,0,0.2);
}
.settings-fields textarea{height:65px;line-height:1.5}
.settings-section-title{
    font-size:13px;color:#8B0000;letter-spacing:2px;padding-top:8px;
    border-top:1px dashed rgba(218,165,32,0.4);margin-top:4px;
}
.settings-pwd-wrap{margin-top:6px;display:none}
.settings-pwd-wrap.show{display:block}
.settings-pwd-wrap input{
    width:100%;padding:10px 12px;border:1.5px solid #C4956A;border-radius:5px;
    font-size:14px;font-family:inherit;background:#FFF;color:#3E2723;
    transition:border-color .3s;
}
.settings-pwd-wrap input:focus{outline:none;border-color:#8B0000;box-shadow:0 0 6px rgba(139,0,0,0.2)}

/* ---- CONTEXT MENU BACKDROP ---- */
.ctx-backdrop{position:fixed;top:0;left:0;right:0;bottom:0;z-index:249;display:none}
.ctx-backdrop.on{display:block}

/* ---- FLOATING VIEW SWITCH ---- */
.view-switch-wrap{
    position:fixed;bottom:20px;left:20px;z-index:99;
    display:flex;flex-direction:column;align-items:flex-start;gap:8px;
}
.view-switch-btn{
    width:44px;height:44px;border-radius:50%;
    background:linear-gradient(135deg,#8B0000,#6B0000);
    color:#FFD700;border:2px solid #DAA520;
    font-size:20px;cursor:pointer;font-family:inherit;
    box-shadow:0 3px 14px rgba(0,0,0,0.3);
    display:flex;align-items:center;justify-content:center;
    transition:transform .3s,box-shadow .3s;
    user-select:none;-webkit-tap-highlight-color:transparent;
}
.view-switch-btn:hover{transform:scale(1.08);box-shadow:0 4px 18px rgba(0,0,0,0.4)}
.view-switch-btn.open{transform:rotate(180deg)}

.view-switch-menu{
    display:none;flex-direction:column;gap:6px;
    background:#FFF8F0;border:2px solid #DAA520;border-radius:12px;
    padding:10px;box-shadow:0 4px 18px rgba(0,0,0,0.22);
    animation:popIn .2s ease;
}
.view-switch-menu.on{display:flex}

.view-switch-option{
    padding:10px 20px;border:1.5px solid #C4956A;border-radius:8px;
    background:#FFF;color:#5D4037;font-size:14px;cursor:pointer;
    font-family:inherit;letter-spacing:2px;text-align:center;
    white-space:nowrap;transition:all .2s;
}
.view-switch-option:hover{border-color:#8B0000;background:#FFF5F5}
.view-switch-option.active{border-color:#8B0000;background:#8B0000;color:#FFD700;font-weight:bold}

/* ---- VERTICAL TREE ---- */
.tree-wrap.vertical{
    display:flex;flex-direction:row;align-items:flex-start;
    padding:40px 24px;min-width:max-content;overflow-x:auto;
}
.tree-wrap.vertical .tnode{
    display:flex;flex-direction:row;align-items:center;gap:0;
}
.tree-wrap.vertical .vnchildren{
    display:flex;flex-direction:column;gap:16px;
    position:relative;margin-left:40px;
    align-items:stretch;
}
.tree-wrap.vertical .vnchildren::before{
    content:'';position:absolute;
    left:-40px;top:50%;width:20px;height:2px;
    background:#8B4513;
    transform:translateY(-1px);
}
.tree-wrap.vertical .vnchild{
    display:flex;flex-direction:row;align-items:center;
    position:relative;padding:0;
}
.tree-wrap.vertical .vnchild::before{
    content:'';position:absolute;
    left:-20px;top:50%;width:20px;height:2px;
    background:#8B4513;
    transform:translateY(-1px);
}
.tree-wrap.vertical .vnchild::after{
    content:'';position:absolute;
    left:-20px;top:-10px;bottom:-10px;width:2px;
    background:#8B4513;
}
.tree-wrap.vertical .vnchild:first-child::after{top:50%}
.tree-wrap.vertical .vnchild:last-child::after{bottom:50%}
.tree-wrap.vertical .vnchild:first-child:last-child::after{display:none}
.tree-wrap.vertical .ncard{margin:0;flex-shrink:0}
.tree-wrap.vertical .nvline{display:none}
.tree-wrap.vertical .nchildren.vwrap{
    display:flex;flex-direction:column;gap:12px;
}

/* ---- LIST TREE ---- */
.tree-wrap.list{
    display:block;padding:0;overflow-x:hidden;
}
.list-node{margin-left:0;padding:2px 0}
.list-node-item{
    display:flex;align-items:center;gap:4px;padding:6px 8px 6px 8px;
    border-left:3px solid #DAA520;margin:2px 0;
    background:rgba(255,248,240,0.6);border-radius:0 6px 6px 0;
    transition:background .2s;flex-wrap:wrap;cursor:default;
}
.list-node-item:hover{background:rgba(255,240,224,0.8)}
.list-node-item .ln-caret{
    width:14px;height:14px;flex-shrink:0;font-size:10px;line-height:14px;
    text-align:center;color:#8B6914;cursor:pointer;
    transition:transform .2s;display:none;
}
.list-node-item .ln-caret.show{display:inline-block}
.list-node.collapsed>.list-children{display:none}
.list-node.collapsed>.list-node-item .ln-caret{transform:rotate(-90deg)}
.list-node-item .ln-name{color:#8B0000;font-weight:bold;letter-spacing:2px;font-size:14px;flex-shrink:0}
.list-node-item .ln-born{color:#8B6914;font-size:12px;margin-left:2px}
.list-node-item .ln-spouse{color:#8B6914;font-size:13px;margin-left:6px}
.list-node-item .ln-spouse .ln-spouse-born{font-size:11px}
.list-node-item .ln-fu{font-size:13px;background:linear-gradient(135deg,#FFD700,#FFA000);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;flex-shrink:0}
.list-node-item .ln-info{color:#999;font-size:11px;margin-left:6px}
.list-node-item.root-item{border-left:3px solid #8B0000;background:rgba(255,232,208,0.7)}

.list-node-item.level-1{border-left-color:#C0392B}
.list-node-item.level-2{border-left-color:#E8A87C}
.list-node-item.level-3{border-left-color:#A8C8E0}
.list-node-item.level-4{border-left-color:#B5C99A}
.list-node-item.level-5{border-left-color:#C8B8D8}
.list-node-item.level-6{border-left-color:#E8D0C8}
.list-node-item.level-7{border-left-color:#D8CEC8}
.list-node-item.level-8{border-left-color:#E8E0D8}
.list-node-item.level-9{border-left-color:#F0EBE4}

.list-children{margin-left:2em}
.list-node .list-children{overflow:hidden;transition:max-height .3s ease}
.list-node.collapsed>.list-children{display:none}

/* ---- RESPONSIVE ---- */
@media(max-width:768px){
    .ncard{padding:8px 10px;min-width:60px;border-radius:6px}
    .ncard.root-card{padding:10px 14px;min-width:70px}
    .nname{font-size:13px;letter-spacing:1px}
    .root-card .nname{font-size:16px}
    .modal-box{padding:18px 14px}
    .rel-btns{gap:5px}
    .rel-btn{padding:8px 4px;font-size:12px;min-width:48px}
    .list-node-item{padding:5px 6px;font-size:13px}
    .list-node-item .ln-caret{width:12px;height:12px;font-size:9px;line-height:12px}
    .list-node-item .ln-name{font-size:13px;letter-spacing:1px}
    .list-node-item .ln-spouse{font-size:12px}
    .list-children{margin-left:1.2em}
}
@media(max-width:480px){
    .ncard{padding:6px 5px;min-width:44px;border-width:1.5px}
    .ncard.root-card{padding:8px 10px;min-width:54px}
    .nname{font-size:11px;letter-spacing:0}
    .root-card .nname{font-size:13px}
    .nplus{font-size:10px;padding:1px 5px}
    .nspouse{font-size:10px}
    .nvline{height:22px;width:2px}
    .nchild{padding-top:22px}
    .nchildren{gap:8px}
    .nchild::before{height:2px;left:-4px;right:-4px}
    .nchild:first-child::before{left:50%}
    .nchild:last-child::before{right:50%}
    .nchild::after{height:22px;width:2px;margin-left:-1px}
    .header-section .main-title{font-size:20px;letter-spacing:3px}
    .header-section .main-title::before,.header-section .main-title::after{margin:0 5px;font-size:9px}
    .list-node-item{padding:4px 4px;font-size:11px;gap:2px}
    .list-node-item .ln-caret{width:10px;height:10px;font-size:8px;line-height:10px}
    .list-node-item .ln-name{font-size:12px;letter-spacing:0}
    .list-node-item .ln-born{font-size:10px}
    .list-node-item .ln-spouse{font-size:11px}
    .list-node-item .ln-info{font-size:10px}
    .list-children{margin-left:1em}
}

/* ---- HELP BUTTON ---- */
.help-btn{
    position:fixed;bottom:90px;right:20px;z-index:150;
    width:46px;height:46px;border-radius:50%;
    background:linear-gradient(135deg,#1565C0,#0D47A1);
    color:#fff;border:2px solid #90CAF9;
    font-size:22px;font-weight:bold;cursor:pointer;font-family:inherit;
    box-shadow:0 3px 14px rgba(0,0,0,0.3);
    display:none;align-items:center;justify-content:center;
    transition:transform .3s,opacity .3s;opacity:0;
    user-select:none;-webkit-tap-highlight-color:transparent;
}
.help-btn.on{display:flex;opacity:1;animation:popIn .3s ease}
.help-btn:hover{transform:scale(1.1)}
</style>
</head>
<body>

<div class="header-section">
    <div class="main-title"><?php echo htmlspecialchars($surname); ?> 氏 家 族 脉 系 图</div>
    <div class="sub-title">参天之木，必有其根；怀山之水，必有其源</div>
    <div class="divider"></div>
</div>

<div class="main-wrap" id="mainWrap"><div class="tree-wrap" id="treeWrap"></div></div>

<div class="modal" id="addModal">
    <div class="modal-box">
        <h3 id="addTitle">添加直亲</h3>
        <div class="rel-btns" id="relBtns">
            <button class="rel-btn" data-rel="elder">兄|姐</button>
            <button class="rel-btn" data-rel="younger">弟|妹</button>
            <button class="rel-btn" data-rel="child">子 女</button>
            <button class="rel-btn" data-rel="spouse">配 偶</button>
        </div>
        <input type="text" id="addName" placeholder="请先选择关系，再输入姓名">
        <div class="modal-btns">
            <button class="btn-no" onclick="closeAdd()">取 消</button>
            <button class="btn-ok" id="btnAddOk" onclick="confirmAdd()">确 定</button>
        </div>
    </div>
</div>

<div class="modal" id="delModal">
    <div class="modal-box">
        <h3 id="delTitle">删除族人</h3>
        <input type="password" id="delPwd" placeholder="请输入删除密码">
        <div class="modal-btns">
            <button class="btn-no" onclick="closeDel()">取 消</button>
            <button class="btn-ok" style="background:linear-gradient(180deg,#C62828,#8B0000);color:#fff" onclick="confirmDel()">确认删除</button>
        </div>
    </div>
</div>

<div class="version-bar">
    <button class="vb-save" onclick="openSaveVersion()">版本</button>
    <span class="vb-sep">|</span>
    <div class="vb-dates" id="versionList">
        <span class="vb-empty">暂无历史版本</span>
    </div>
</div>

<div class="modal" id="saveVerModal">
    <div class="modal-box">
        <h3>保存历史版本</h3>
        <input type="password" id="saveVerPwd" placeholder="请输入保存密码">
        <div class="modal-btns">
            <button class="btn-no" onclick="closeSaveVer()">取 消</button>
            <button class="btn-ok" onclick="confirmSaveVersion()">确认保存</button>
        </div>
    </div>
</div>

<div class="modal" id="viewVerModal">
    <div class="modal-box modal-box-wide">
        <h3 id="viewVerTitle">历史版本</h3>
        <div class="view-ver-meta" id="viewVerMeta"></div>
        <div class="view-ver-tree-wrap" id="viewVerTreeWrap"><div class="tree-wrap"></div></div>
        <div class="modal-btns">
            <button class="btn-no" onclick="closeViewVer()">关 闭</button>
        </div>
    </div>
</div>

<div class="modal" id="blessModal">
    <div class="bless-modal-box">
        <div class="bless-title">诚心祈福，福泽绵长</div>
        <div class="bless-subtitle"></div>
        <div class="bless-btn-wrap">
            <div class="bless-glow"></div>
            <button class="bless-btn" id="blessBtn" onclick="doBless()">
                <span class="bless-emoji">🙏</span>
            </button>
        </div>
        <div class="bless-count">福份：<span class="count-num" id="blessCount">0</span> 个</div>
        <div class="bless-scroll-wrap" id="blessScrollWrap" style="display:none">
            <div class="bless-scroll-inner" id="blessScrollInner"></div>
        </div>
        <textarea class="bless-msg-input" id="blessMsg" placeholder="写下您的祈福留言..." maxlength="300"></textarea>
        <div class="bless-msg-hint"><span id="blessCharCount">0</span>/300</div>
        <div class="modal-btns">
            <button class="btn-ok" onclick="submitBlessMessage()">提交留言</button>
            <button class="btn-no" onclick="closeBless()">关 闭</button>
        </div>
    </div>
</div>

<div class="ctx-backdrop" id="ctxBackdrop" onclick="closeCtxMenu()"></div>
<div class="ctx-menu" id="ctxMenu">
    <button class="ctx-menu-item" onclick="ctxSettings()">设 置</button>
    <button class="ctx-menu-item" onclick="ctxAdd()">添 加</button>
    <button class="ctx-menu-item ctx-del" onclick="ctxDelete()">删 除</button>
</div>

<div class="modal" id="settingsModal">
    <div class="modal-box" style="max-width:420px">
        <h3 id="settingsTitle">编辑族人信息</h3>
        <div class="settings-fields">
            <div>
                <label>姓名</label>
                <input type="text" id="setName" placeholder="姓名">
            </div>
            <div>
                <label>配偶姓名</label>
                <input type="text" id="setSpouse" placeholder="配偶姓名（留空则无配偶）">
            </div>
            <div>
                <label>生卒年月日</label>
                <input type="text" id="setBorn" placeholder="例如：1920年 - 2000年">
            </div>
            <div>
                <label>事迹</label>
                <textarea id="setInfo" placeholder="生平事迹..."></textarea>
            </div>
            <div id="spouseExtraFields" style="display:none">
                <div class="settings-section-title">配偶信息</div>
                <div style="margin-top:8px">
                    <label>配偶生卒年月日</label>
                    <input type="text" id="setSpouseBorn" placeholder="例如：1922年 - 2005年">
                </div>
                <div style="margin-top:8px">
                    <label>配偶事迹</label>
                    <textarea id="setSpouseInfo" placeholder="配偶生平事迹..."></textarea>
                </div>
            </div>
        </div>
        <div class="settings-pwd-wrap" id="settingsPwdWrap">
            <input type="password" id="setPwd" placeholder="请输入密码以确认修改生卒信息">
        </div>
        <div class="modal-btns">
            <button class="btn-no" onclick="closeSettings()">取 消</button>
            <button class="btn-ok" onclick="confirmSettings()">保 存</button>
        </div>
    </div>
</div>

<div class="view-switch-wrap" id="viewSwitchWrap">
    <button class="view-switch-btn" id="viewSwitchBtn" onclick="toggleViewMenu()" title="切换查看方式">☰</button>
    <div class="view-switch-menu" id="viewSwitchMenu">
        <button class="view-switch-option active" data-view="horizontal" onclick="switchView('horizontal')">横 向</button>
        <button class="view-switch-option" data-view="vertical" onclick="switchView('vertical')">竖 向</button>
        <button class="view-switch-option" data-view="list" onclick="switchView('list')">列 表</button>
        <button class="view-switch-option" style="border-color:#1565C0;color:#1565C0" onclick="shareTree()">分享<?php echo htmlspecialchars($surname); ?>氏家谱</button>
        <a class="view-switch-option" href="index.php" style="text-decoration:none;margin-top:6px;border-color:#2E7D32;color:#2E7D32">创建家谱</a>
        
    </div>
</div>

<button class="help-btn" id="helpBtn" onclick="showHelp()" title="帮助">?</button>

<div class="toast" id="toast"></div>

<script>
var tree = <?php echo json_encode($treeData['root'], JSON_UNESCAPED_UNICODE); ?>;
var fileVersion = <?php echo $fileVersion; ?>;
var appPassword = <?php echo json_encode($appPassword); ?>;
var treeId = <?php echo json_encode($treeId); ?>;
var addTargetId = null;
var addRel = null;
var delTargetId = null;
var ctxNodeId = null;
var settingsNodeId = null;
var settingsOrigBorn = '';
var settingsOrigSpouseBorn = '';
var idCnt = 200;

function gid(){idCnt++;return 'n'+idCnt+'_'+Date.now()}

function initIds(node){
    if(!node.id)node.id=gid();
    if(node.children)for(var i=0;i<node.children.length;i++)initIds(node.children[i]);
}
if(tree&&typeof tree==='object')initIds(tree);

function toast(m){
    var t=document.getElementById('toast');t.innerHTML=m.replace(/\n/g,'<br>');t.classList.add('on');
    clearTimeout(t._t);t._t=setTimeout(function(){t.classList.remove('on')},1800);
}

function saveTree(onConflict){
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            if(r.version)fileVersion=r.version;
        }else if(r.conflict){
            toast('其他人已修改族谱，正在刷新...');
            tree=r.tree||tree;
            fileVersion=r.version||fileVersion;
            if(onConflict)onConflict();
            render();
        }
    };
    x.send(JSON.stringify({action:'save',tree:tree,version:fileVersion,treeId:treeId}));
}

function findNode(root,id){
    if(!root)return null;
    if(root.id===id)return root;
    if(root.children)for(var i=0;i<root.children.length;i++){
        var f=findNode(root.children[i],id);if(f)return f;
    }
    return null;
}

function findParent(root,id){
    if(!root||!root.children)return null;
    for(var i=0;i<root.children.length;i++){
        if(root.children[i].id===id)return root;
        var f=findParent(root.children[i],id);if(f)return f;
    }
    return null;
}

function idxInParent(parent,id){
    if(!parent||!parent.children)return -1;
    for(var i=0;i<parent.children.length;i++)if(parent.children[i].id===id)return i;
    return -1;
}

function startRename(el,nodeId){
    var node=findNode(tree,nodeId);if(!node)return;
    var plus=el.nextElementSibling;
    if(plus&&plus.classList.contains('nplus'))plus.classList.add('show');
    var inp=document.createElement('input');
    inp.type='text';
    inp.className=el.closest('.root-card')?'nname-input root-card':'nname-input';
    inp.value=node.name||'';
    inp.style.width=(el.offsetWidth+20)+'px';
    el.replaceWith(inp);
    inp.focus();inp.select();
    inp.addEventListener('blur',function(e){
        setTimeout(function(){commitRename(inp,nodeId)},150);
    });
    inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();commitRename(inp,nodeId)}});
    inp.addEventListener('keydown',function(e){if(e.key==='Escape'){inp.blur()}});
}

function commitRename(inp,nodeId){
    var node=findNode(tree,nodeId);
    var name=inp.value.trim();
    if(name&&node){
        node.name=name;
        saveTree(function(){
            var n=findNode(tree,nodeId);
            if(n&&n.name!==name){n.name=name;saveTree()}
        });
    }
    render();
}

function openAdd(nodeId){
    addTargetId=nodeId;addRel=null;
    var node=findNode(tree,nodeId);
    document.getElementById('addTitle').textContent='为「'+(node?node.name:'')+'」添加一位直亲';
    document.getElementById('addName').value='';
    document.getElementById('addName').placeholder='请先选择关系，再输入姓名';
    var okBtn=document.getElementById('btnAddOk');
    okBtn.textContent='确 定';
    var btns=document.querySelectorAll('#relBtns .rel-btn');
    for(var i=0;i<btns.length;i++)btns[i].classList.remove('sel');
    document.getElementById('addModal').classList.add('on');
    setTimeout(function(){document.getElementById('addName').focus()},200);

    btns.forEach(function(b){
        b.onclick=function(){
            addRel=this.getAttribute('data-rel');
            for(var j=0;j<btns.length;j++)btns[j].classList.remove('sel');
            this.classList.add('sel');
            document.getElementById('addName').placeholder=addRel==='spouse'?'请输入配偶姓名':'请输入姓名';
            okBtn.textContent='确 定';
            document.getElementById('addName').focus();
        };
    });
}

function confirmAdd(){
    if(!addTargetId||!addRel){toast('请先选择关系类型');return}
    var name=document.getElementById('addName').value.trim();
    if(!name){toast('请输入姓名');return}

    if(addRel==='spouse'){
        var node=findNode(tree,addTargetId);
        if(node){node.spouse=name;saveTree();toast('配偶已添加')}
        render();closeAdd();return;
    }

    var newNode={id:gid(),name:name,spouse:'',born:'',info:'',children:[]};

    if(addRel==='child'){
        var tn=findNode(tree,addTargetId);
        if(tn){if(!tn.children)tn.children=[];tn.children.push(newNode)}
        toast('子女已添加');
        saveTree();render();closeAdd();return;
    }

    var pn=findParent(tree,addTargetId);
    if(!pn){toast('根节点无法添加兄弟姐妹，请先往上添加长辈');closeAdd();return}
    var idx=idxInParent(pn,addTargetId);
    if(addRel==='elder')pn.children.splice(idx,0,newNode);
    else pn.children.splice(idx+1,0,newNode);
    toast(addRel==='elder'?'兄/姐已添加':'弟/妹已添加');

    var savedTargetId=addTargetId,savedRel=addRel,savedName=name;
    closeAdd();render();

    saveTree(function(){
        toast('其他人同时在此位置添加了兄弟姐妹，顺序已变化');
        showSiblingReconfirm(savedTargetId,savedRel,savedName);
    });
}

function showSiblingReconfirm(targetId,rel,name){
    var node=findNode(tree,targetId);
    var pn=findParent(tree,targetId);
    if(!pn||!node){render();return}
    var idx=idxInParent(pn,targetId);
    var sibs=pn.children;
    var desc='';
    if(rel==='elder'){
        desc=idx>0?'将在「'+sibs[idx-1].name+'」与「'+sibs[idx].name+'」之间插入':'将在「'+sibs[idx].name+'」前面';
    }else{
        desc=idx<sibs.length-1?'将在「'+sibs[idx].name+'」与「'+sibs[idx+1].name+'」之间插入':'将在「'+sibs[idx].name+'」后面';
    }
    addTargetId=targetId;addRel=rel;
    document.getElementById('addTitle').textContent=desc+' '+addRelLabel(rel)+'「'+name+'」？';
    document.getElementById('addName').value=name;
    document.getElementById('btnAddOk').textContent='确认添加';
    var btns=document.querySelectorAll('#relBtns .rel-btn');
    for(var i=0;i<btns.length;i++)btns[i].classList.remove('sel');
    for(var j=0;j<btns.length;j++){if(btns[j].getAttribute('data-rel')===rel)btns[j].classList.add('sel')}
    document.getElementById('addModal').classList.add('on');
    document.getElementById('addName').focus();
}

function addRelLabel(r){return r==='elder'?'兄/姐':r==='younger'?'弟/妹':r==='child'?'子女':'配偶'}

function closeAdd(){addTargetId=null;addRel=null;document.getElementById('addModal').classList.remove('on')}

function openDel(nodeId){
    if(nodeId===tree.id){toast('不能删除根节点');return}
    delTargetId=nodeId;
    var node=findNode(tree,nodeId);
    document.getElementById('delTitle').textContent='删除「'+(node?node.name:'')+'」及其后代？';
    document.getElementById('delPwd').value='';
    document.getElementById('delModal').classList.add('on');
    setTimeout(function(){document.getElementById('delPwd').focus()},200);
}

function confirmDel(){
    var pwd=document.getElementById('delPwd').value;
    if(pwd!==appPassword){toast('密码错误，删除取消');closeDel();return}
    if(!delTargetId)return;
    var pn=findParent(tree,delTargetId);
    if(!pn){closeDel();return}
    var idx=idxInParent(pn,delTargetId);
    if(idx>=0)pn.children.splice(idx,1);
    var node=findNode(tree,delTargetId);
    var n=node?node.name:'';
    delTargetId=null;
    document.getElementById('delModal').classList.remove('on');
    saveTree();render();toast('「'+n+'」已删除');
}

function closeDel(){delTargetId=null;document.getElementById('delModal').classList.remove('on')}

/* ---- CONTEXT MENU ---- */
function showCtxMenu(nodeId,x,y){
    ctxNodeId=nodeId;
    var menu=document.getElementById('ctxMenu');
    var backdrop=document.getElementById('ctxBackdrop');
    var mw=menu.offsetWidth||130;
    var mh=menu.offsetHeight||110;
    var left=Math.min(x,window.innerWidth-mw-10);
    var top=Math.min(y,window.innerHeight-mh-10);
    left=Math.max(left,5);
    top=Math.max(top,5);
    menu.style.left=left+'px';
    menu.style.top=top+'px';
    menu.classList.add('on');
    backdrop.classList.add('on');
}

function closeCtxMenu(){
    ctxNodeId=null;
    document.getElementById('ctxMenu').classList.remove('on');
    document.getElementById('ctxBackdrop').classList.remove('on');
}

function ctxSettings(){
    var nid=ctxNodeId;closeCtxMenu();
    if(nid)openSettings(nid);
}

function ctxDelete(){
    var nid=ctxNodeId;closeCtxMenu();
    if(nid)openDel(nid);
}

function ctxAdd(){
    var nid=ctxNodeId;closeCtxMenu();
    if(nid)openAdd(nid);
}

/* ---- SETTINGS ---- */
function openSettings(nodeId){
    var node=findNode(tree,nodeId);
    if(!node)return;
    settingsNodeId=nodeId;
    settingsOrigBorn=node.born||'';
    settingsOrigSpouseBorn=node.spouseBorn||'';
    document.getElementById('settingsTitle').textContent='编辑「'+(node.name||'')+'」信息';
    document.getElementById('setName').value=node.name||'';
    document.getElementById('setSpouse').value=node.spouse||'';
    document.getElementById('setBorn').value=node.born||'';
    document.getElementById('setInfo').value=node.info||'';
    document.getElementById('setSpouseBorn').value=node.spouseBorn||'';
    document.getElementById('setSpouseInfo').value=node.spouseInfo||'';
    document.getElementById('setPwd').value='';
    document.getElementById('settingsPwdWrap').classList.remove('show');
    updateSpouseExtraFields();
    document.getElementById('settingsModal').classList.add('on');
    setTimeout(function(){document.getElementById('setName').focus()},200);
}

function updateSpouseExtraFields(){
    var spouseName=document.getElementById('setSpouse').value.trim();
    var extra=document.getElementById('spouseExtraFields');
    if(spouseName){extra.style.display='block'}else{
        extra.style.display='none';
        document.getElementById('setSpouseBorn').value='';
        document.getElementById('setSpouseInfo').value='';
    }
}

document.getElementById('setSpouse').addEventListener('input',updateSpouseExtraFields);

function updatePwdVisibility(){
    var curBorn=document.getElementById('setBorn').value.trim();
    var curSpouseBorn=document.getElementById('setSpouseBorn').value.trim();
    var bornNeedsPwd=(!settingsOrigBorn && curBorn);
    var spouseBornNeedsPwd=(!settingsOrigSpouseBorn && curSpouseBorn);
    var wrap=document.getElementById('settingsPwdWrap');
    if(bornNeedsPwd||spouseBornNeedsPwd){
        wrap.classList.add('show');
    }else{
        wrap.classList.remove('show');
        document.getElementById('setPwd').value='';
    }
}
document.getElementById('setBorn').addEventListener('input',updatePwdVisibility);
document.getElementById('setSpouseBorn').addEventListener('input',updatePwdVisibility);

function closeSettings(){
    settingsNodeId=null;
    settingsOrigBorn='';
    settingsOrigSpouseBorn='';
    document.getElementById('settingsModal').classList.remove('on');
}

function confirmSettings(){
    if(!settingsNodeId)return;
    var node=findNode(tree,settingsNodeId);
    if(!node)return;

    var newName=document.getElementById('setName').value.trim();
    var newSpouse=document.getElementById('setSpouse').value.trim();
    var newBorn=document.getElementById('setBorn').value.trim();
    var newInfo=document.getElementById('setInfo').value.trim();
    var newSpouseBorn=document.getElementById('setSpouseBorn').value.trim();
    var newSpouseInfo=document.getElementById('setSpouseInfo').value.trim();

    if(!newName){toast('姓名不能为空');return}

    var bornNeedsPwd=(!settingsOrigBorn && newBorn);
    var spouseBornNeedsPwd=(!settingsOrigSpouseBorn && newSpouseBorn);

    if(bornNeedsPwd||spouseBornNeedsPwd){
        var pwd=document.getElementById('setPwd').value;
        if(pwd!==appPassword){toast('密码错误，新增生卒信息需验证密码');return}
    }

    node.name=newName;
    node.spouse=newSpouse||'';
    node.born=newBorn;
    node.info=newInfo;
    node.spouseBorn=newSpouseBorn;
    node.spouseInfo=newSpouseInfo;

    if(!node.spouseBorn)delete node.spouseBorn;
    if(!node.spouseInfo)delete node.spouseInfo;
    if(!node.born)delete node.born;
    if(!node.info)delete node.info;
    if(!node.spouse)delete node.spouse;

    closeSettings();
    saveTree();render();
    toast('信息已保存');
}

document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){closeAdd();closeDel();closeCtxMenu();closeSettings();closeViewMenu();}
    if(e.key==='Enter'&&document.getElementById('addModal').classList.contains('on'))confirmAdd();
    if(e.key==='Enter'&&document.getElementById('delModal').classList.contains('on'))confirmDel();
    if(e.key==='Enter'&&document.getElementById('settingsModal').classList.contains('on'))confirmSettings();
});
document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)closeAdd()});
document.getElementById('delModal').addEventListener('click',function(e){if(e.target===this)closeDel()});
document.getElementById('settingsModal').addEventListener('click',function(e){if(e.target===this)closeSettings()});

var longPressTimer=null;
var longPressNodeId=null;

document.getElementById('treeWrap').addEventListener('contextmenu',function(e){
    var card=e.target.closest('.ncard');
    if(!card)return;
    e.preventDefault();
    var nid=card.getAttribute('data-id');
    if(nid)showCtxMenu(nid,e.clientX,e.clientY);
});

document.getElementById('treeWrap').addEventListener('touchstart',function(e){
    var card=e.target.closest('.ncard');
    if(!card)return;
    longPressNodeId=card.getAttribute('data-id');
    var touch=e.touches[0];
    var cx=touch?touch.clientX:0;
    var cy=touch?touch.clientY:0;
    longPressTimer=setTimeout(function(){
        if(longPressNodeId){showCtxMenu(longPressNodeId,cx,cy)}
        longPressTimer=null;
    },600);
},{passive:true});

document.getElementById('treeWrap').addEventListener('touchend',function(){
    if(longPressTimer){clearTimeout(longPressTimer);longPressTimer=null;}
    longPressNodeId=null;
});

function esc(s){
    var d=document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

/* ---- VERSION MANAGEMENT ---- */
function openSaveVersion(){
    document.getElementById('saveVerPwd').value='';
    document.getElementById('saveVerModal').classList.add('on');
    setTimeout(function(){document.getElementById('saveVerPwd').focus()},200);
}

function closeSaveVer(){document.getElementById('saveVerModal').classList.remove('on')}

function confirmSaveVersion(){
    var pwd=document.getElementById('saveVerPwd').value;
    if(pwd!==appPassword){toast('密码错误，保存取消');closeSaveVer();return}
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            toast('版本 v'+r.version+' 已保存 ('+r.savedAt+')');
            loadVersionList();
        }else{
            toast('保存失败: '+(r.error||'未知错误'));
        }
    };
    x.send(JSON.stringify({action:'saveVersion',password:pwd,tree:tree,treeId:treeId}));
    closeSaveVer();
}

function loadVersionList(){
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success&&r.versions){
            var list=document.getElementById('versionList');
            if(r.versions.length===0){
                list.innerHTML='<span class="vb-empty">暂无历史版本</span>';
                return;
            }
            var h='';
            for(var i=0;i<r.versions.length;i++){
                var v=r.versions[i];
                var dateStr=v.savedAt.split(' ')[0];
                h+='<span class="vb-date-item" onclick="viewVersion(\''+v.file+'\')">'+dateStr+'</span>';
            }
            list.innerHTML=h;
        }
    };
    x.send(JSON.stringify({action:'listVersions',treeId:treeId}));
}

function viewVersion(file){
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success&&r.data){
            document.getElementById('viewVerTitle').textContent='历史版本 v'+r.data.version;
            document.getElementById('viewVerMeta').innerHTML=
                '<span class="ver-label">保存时间：</span><span class="ver-value">'+r.data.savedAt+'</span>&nbsp;&nbsp;'+
                '<span class="ver-label">版本号：</span><span class="ver-value">v'+r.data.version+'</span>';
            var root=r.data.root||null;
            var treeEl=document.getElementById('viewVerTreeWrap');
            if(root&&typeof root==='object'){
                treeEl.innerHTML='<div class="tree-wrap" style="justify-content:center;min-width:max-content"><div class="tnode">'+buildReadOnlyNode(root,true)+'</div></div>';
            }else{
                treeEl.innerHTML='<div class="tree-wrap" style="text-align:center;color:#999;padding:20px">暂无族谱数据</div>';
            }
            document.getElementById('viewVerModal').classList.add('on');
        }else{
            toast('加载版本失败');
        }
    };
    x.send(JSON.stringify({action:'loadVersion',file:file,treeId:treeId}));
}

function buildReadOnlyNode(node,isRoot){
    if(!node)return'';
    var h='';
    h+='<div class="tnode">';
    h+='<div class="ncard'+(isRoot?' root-card':'')+'">';
    var nameText=esc(node.name||'未命名');
    if(node.born)nameText+='（'+esc(node.born)+'）';
    h+='<span class="'+(isRoot?'nname root-card':'nname')+'">'+nameText+'</span>';
    if(node.born)h+='<span class="fu-badge">福</span>';
    if(node.spouse){
        var spouseText=esc(node.spouse);
        if(node.spouseBorn)spouseText+='（'+esc(node.spouseBorn)+'）';
        h+='<div class="nspouse">'+spouseText;
        if(node.spouseBorn)h+='<span class="fu-badge">福</span>';
        h+='</div>';
    }
    if(node.spouseInfo)h+='<div class="ninfo">配偶事迹：'+esc(node.spouseInfo)+'</div>';
    if(node.info)h+='<div class="ninfo">'+esc(node.info)+'</div>';
    h+='</div>';
    if(node.children&&node.children.length>0){
        h+='<div class="nvline"></div>';
        h+='<div class="nchildren">';
        for(var i=0;i<node.children.length;i++){
            h+='<div class="nchild">'+buildReadOnlyNode(node.children[i],false)+'</div>';
        }
        h+='</div>';
    }
    h+='</div>';
    return h;
}

function closeViewVer(){document.getElementById('viewVerModal').classList.remove('on')}

document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){closeSaveVer();closeViewVer();closeBless();closeCtxMenu();closeSettings();closeViewMenu();}
    if(e.key==='Enter'&&document.getElementById('saveVerModal').classList.contains('on'))confirmSaveVersion();
});
document.getElementById('saveVerModal').addEventListener('click',function(e){if(e.target===this)closeSaveVer()});
document.getElementById('viewVerModal').addEventListener('click',function(e){if(e.target===this)closeViewVer()});

/* ---- BLESSING ---- */
var blessData={totalCount:0,messages:[]};
var blessScrollSpeed=30;

function openBless(){
    document.getElementById('blessModal').classList.add('on');
    document.getElementById('blessMsg').value='';
    document.getElementById('blessCharCount').textContent='0';
    loadBlessings();
}

function closeBless(){document.getElementById('blessModal').classList.remove('on')}

function loadBlessings(cb){
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success&&r.data){
            blessData=r.data;
            updateBlessUI();
            if(cb)cb();
        }
    };
    x.send(JSON.stringify({action:'loadBlessings',treeId:treeId}));
}

function updateBlessUI(){
    document.getElementById('blessCount').textContent=blessData.totalCount||0;
    renderBlessScroll();
}

function doBless(){
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            blessData=r.data;
            updateBlessUI();
            toast('您的福份+1');
        }
    };
    x.send(JSON.stringify({action:'addBlessing',treeId:treeId}));
}

function submitBlessMessage(){
    var msg=document.getElementById('blessMsg').value.trim();
    var x=new XMLHttpRequest();
    x.open('POST','',true);
    x.setRequestHeader('Content-Type','application/json;charset=UTF-8');
    x.onload=function(){
        if(x.status!==200)return;
        try{var r=JSON.parse(x.responseText)}catch(e){return}
        if(r.success){
            blessData=r.data;
            updateBlessUI();
            document.getElementById('blessMsg').value='';
            document.getElementById('blessCharCount').textContent='0';
            toast('您的福份+1，留言已记录');
        }
    };
    x.send(JSON.stringify({action:'addBlessing',message:msg,treeId:treeId}));
}

function renderBlessScroll(){
    var wrap=document.getElementById('blessScrollWrap');
    var inner=document.getElementById('blessScrollInner');
    var msgs=blessData.messages||[];
    if(msgs.length===0){wrap.style.display='none';return}
    wrap.style.display='block';
    var items='';
    for(var i=0;i<msgs.length;i++){
        items+='<span class="bless-scroll-item"> '+escHtml(msgs[i].text)+'</span>';
    }
    var dup=items+items;
    inner.innerHTML=dup;
    var totalWidth=inner.scrollWidth/2;
    var duration=totalWidth/blessScrollSpeed;
    inner.style.animation='none';
    inner.offsetHeight;
    inner.style.animation='blessScroll '+duration+'s linear infinite';
}

function escHtml(s){
    var d=document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

document.getElementById('blessMsg').addEventListener('input',function(){
    document.getElementById('blessCharCount').textContent=this.value.length;
});

document.getElementById('blessModal').addEventListener('click',function(e){if(e.target===this)closeBless()});

/* ---- VIEW SWITCH ---- */
var currentView='horizontal';

function toggleViewMenu(){
    var menu=document.getElementById('viewSwitchMenu');
    var btn=document.getElementById('viewSwitchBtn');
    var isOn=menu.classList.contains('on');
    if(isOn){closeViewMenu()}else{menu.classList.add('on');btn.classList.add('open')}
}

function closeViewMenu(){
    document.getElementById('viewSwitchMenu').classList.remove('on');
    document.getElementById('viewSwitchBtn').classList.remove('open');
}

document.addEventListener('click',function(e){
    var wrap=document.getElementById('viewSwitchWrap');
    if(wrap&&!wrap.contains(e.target)){closeViewMenu()}
});

function switchView(view){
    currentView=view;
    var opts=document.querySelectorAll('.view-switch-option');
    for(var i=0;i<opts.length;i++){
        opts[i].classList.toggle('active',opts[i].getAttribute('data-view')===view);
    }
    toggleViewMenu();
    render();
}

function buildNode(node,isRoot){
    if(!node)return'';
    var h='';
    h+='<div class="tnode">';
    h+='<div class="ncard'+(isRoot?' root-card':'')+'" data-id="'+node.id+'"'+(isRoot?' style="cursor:pointer" onclick="openBless()"':'')+'>';
    h+='<div class="nname-row">';
    var nc=isRoot?'nname root-card':'nname';
    var nameText=esc(node.name||'未命名');
    if(node.born)nameText+='（'+esc(node.born)+'）';
    if(isRoot){
        h+='<span class="'+nc+'" style="cursor:pointer">'+nameText+'</span>';
        if(node.born)h+='<span class="fu-badge">福</span>';
    }else{
        h+='<span class="'+nc+'" onclick="startRename(this,\''+node.id+'\')">'+nameText+'</span>';
        if(node.born)h+='<span class="fu-badge">福</span>';
    }
    var showPlus = isRoot && (!node.children || node.children.length === 0);
    h+='<span class="nplus'+(showPlus?' show':'')+'" onclick="event.stopPropagation();openAdd(\''+node.id+'\')">添加</span>';
    h+='</div>';
    if(node.spouse){
        var spouseText=esc(node.spouse);
        if(node.spouseBorn)spouseText+='（'+esc(node.spouseBorn)+'）';
        h+='<div class="nspouse">'+spouseText;
        if(node.spouseBorn)h+='<span class="fu-badge">福</span>';
        h+='</div>';
    }
    if(node.spouseInfo)h+='<div class="ninfo">配偶事迹：'+esc(node.spouseInfo)+'</div>';
    if(node.info)h+='<div class="ninfo">'+esc(node.info)+'</div>';
    if(isRoot){
        h+='<span class="root-bless-badge">← 点击祈福</span>';
    }
    h+='</div>';

    if(node.children&&node.children.length>0){
        h+='<div class="nvline"></div>';
        h+='<div class="nchildren">';
        for(var i=0;i<node.children.length;i++){
            h+='<div class="nchild">'+buildNode(node.children[i],false)+'</div>';
        }
        h+='</div>';
    }
    h+='</div>';
    return h;
}

function render(){
    var w=document.getElementById('treeWrap');
    var mw=document.getElementById('mainWrap');
    if(!tree){w.innerHTML='<div style="text-align:center;color:#999;padding:40px">暂无族谱数据</div>';return}

    w.className='tree-wrap';
    mw.classList.remove('list-mode');
    if(currentView==='vertical'){
        w.classList.add('vertical');
        w.innerHTML='<div class="tnode vroot">'+buildNodeVertical(tree,true)+'</div>';
    }else if(currentView==='list'){
        w.classList.add('list');
        mw.classList.add('list-mode');
        w.innerHTML=buildNodeList(tree,true,0);
    }else{
        w.innerHTML='<div class="tnode">'+buildNode(tree,true)+'</div>';
    }
}

/* ---- VERTICAL TREE ---- */
function buildNodeVertical(node,isRoot){
    if(!node)return'';
    var h='';
    h+='<div class="tnode'+(isRoot?' vroot':'')+'">';
    h+=buildNodeCard(node,isRoot);
    if(node.children&&node.children.length>0){
        h+='<div class="vnchildren">';
        for(var i=0;i<node.children.length;i++){
            h+='<div class="vnchild">'+buildNodeVertical(node.children[i],false)+'</div>';
        }
        h+='</div>';
    }
    h+='</div>';
    return h;
}

function buildNodeCard(node,isRoot){
    var h='';
    h+='<div class="ncard'+(isRoot?' root-card':'')+'" data-id="'+node.id+'"'+(isRoot?' style="cursor:pointer" onclick="openBless()"':'')+'>';
    h+='<div class="nname-row">';
    var nc=isRoot?'nname root-card':'nname';
    var nameText=esc(node.name||'未命名');
    if(node.born)nameText+='（'+esc(node.born)+'）';
    if(isRoot){
        h+='<span class="'+nc+'" style="cursor:pointer">'+nameText+'</span>';
        if(node.born)h+='<span class="fu-badge">福</span>';
    }else{
        h+='<span class="'+nc+'" onclick="startRename(this,\''+node.id+'\')">'+nameText+'</span>';
        if(node.born)h+='<span class="fu-badge">福</span>';
    }
    var showPlus = isRoot && (!node.children || node.children.length === 0);
    h+='<span class="nplus'+(showPlus?' show':'')+'" onclick="event.stopPropagation();openAdd(\''+node.id+'\')">添加</span>';
    h+='</div>';
    if(node.spouse){
        var spouseText=esc(node.spouse);
        if(node.spouseBorn)spouseText+='（'+esc(node.spouseBorn)+'）';
        h+='<div class="nspouse">'+spouseText;
        if(node.spouseBorn)h+='<span class="fu-badge">福</span>';
        h+='</div>';
    }
    if(node.spouseInfo)h+='<div class="ninfo">配偶事迹：'+esc(node.spouseInfo)+'</div>';
    if(node.info)h+='<div class="ninfo">'+esc(node.info)+'</div>';
    if(isRoot){
        h+='<span class="root-bless-badge">← 点击祈福</span>';
    }
    h+='</div>';
    return h;
}

/* ---- LIST TREE ---- */
function buildNodeList(node,isRoot,depth){
    if(!node)return'';
    var hasChildren=node.children&&node.children.length>0;
    var caretClass=hasChildren?'ln-caret show':'ln-caret';
    var caretHTML='<span class="'+caretClass+'">▼</span>';
    var h='';
    var itemClass='list-node-item'+(isRoot?' root-item':' level-'+depth);
    h+='<div class="list-node'+(isRoot?' list-root':'')+'">';
    h+='<div class="'+itemClass+'" onclick="toggleListCollapse(this)">';
    h+=caretHTML;
    h+='<span class="ln-name">'+esc(node.name||'未命名')+'</span>';
    if(node.born)h+='<span class="ln-born">（'+esc(node.born)+'）</span>';
    if(node.born)h+='<span class="ln-fu">福</span>';
    if(node.spouse){
        h+='<span class="ln-spouse">&emsp;'+esc(node.spouse);
        if(node.spouseBorn)h+='<span class="ln-spouse-born">（'+esc(node.spouseBorn)+'）</span>';
        h+='</span>';
    }
    if(node.info)h+='<span class="ln-info">'+esc(node.info)+'</span>';
    if(node.spouseInfo)h+='<span class="ln-info">配偶事迹：'+esc(node.spouseInfo)+'</span>';
    h+='</div>';
    if(hasChildren){
        h+='<div class="list-children">';
        for(var i=0;i<node.children.length;i++){
            h+=buildNodeList(node.children[i],false,depth+1);
        }
        h+='</div>';
    }
    h+='</div>';
    return h;
}

function toggleListCollapse(el){
    var node=el.parentElement;
    if(!node||!node.classList.contains('list-node'))return;
    if(!node.querySelector('.list-children'))return;
    node.classList.toggle('collapsed');
}

function shareTree(){
    closeViewMenu();
    var url=window.location.href;
    if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(url).then(function(){
            toast('<?php echo htmlspecialchars($surname); ?>氏家谱链接已复制，粘贴发送给家人可共同修改完善！');
        }).catch(function(){
            fallbackCopy(url);
        });
    }else{
        fallbackCopy(url);
    }
}

function fallbackCopy(text){
    var ta=document.createElement('textarea');
    ta.value=text;ta.style.position='fixed';ta.style.left='-9999px';
    document.body.appendChild(ta);ta.select();
    try{document.execCommand('copy');toast('<?php echo htmlspecialchars($surname); ?>氏家谱链接已复制，粘贴发送给家人可共同修改完善！')}
    catch(e){toast('复制失败，请手动复制链接')}
    document.body.removeChild(ta);
}

/* ---- HELP BUTTON (5s inactive) ---- */
var helpTimer=null;
function getCookie(n){var m=document.cookie.match('(^|; )'+n+'=([^;]*)');return m?m[2]:''}
function resetHelpTimer(e){
    if(getCookie('help_shown')==='1')return;
    if(e&&e.target&&e.target.closest&&e.target.closest('#helpBtn'))return;
    var btn=document.getElementById('helpBtn');
    if(btn)btn.classList.remove('on');
    clearTimeout(helpTimer);
    helpTimer=setTimeout(function(){
        if(btn&&getCookie('help_shown')!=='1')btn.classList.add('on');
    },5000);
    if(e){
        var t=document.getElementById('toast');
        if(t.classList.contains('on')&&!t._t)t.classList.remove('on');
    }
}
function showHelp(){
    document.cookie='help_shown=1;max-age='+60*60*24*365+';path=/';
    var btn=document.getElementById('helpBtn');
    if(btn)btn.classList.remove('on');
    clearTimeout(helpTimer);
    var t=document.getElementById('toast');
    clearTimeout(t._t);t._t=null;
    t.innerHTML='点击姓名：可以添加TA的族人哟<br>长按姓名：或右键, 可设置和删除';
    t.classList.add('on');
}
resetHelpTimer();
document.addEventListener('click',function(e){resetHelpTimer(e)});
document.addEventListener('touchstart',function(e){resetHelpTimer(e)});
document.addEventListener('keydown',function(e){resetHelpTimer(e)});
document.addEventListener('scroll',function(e){resetHelpTimer(e)});

loadVersionList();
loadBlessings();
render();
</script>

</body>
</html>