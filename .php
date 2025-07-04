<?php
// 配置参数
$source_url = 'http://192.168.3.88:5678/tv.txt?url=default'; // 修改载入接口地址
$db_host = 'localhost';
$db_user = 'root'; // 数据库用户名
$db_pass = 'wq@123123'; // 数据库密码
$db_name = 'diyp'; // 数据库名称
$table_name = 'itv_channels'; // 数据表
$backup_dir = __DIR__;
$max_backups = 7; // 保留近七次更新数据
$log_file = __DIR__ . '/channel_update.log'; // 日志文件路径

// 错误报告设置
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// ================== 函数定义 ==================

/**
 * 记录日志（同时输出到屏幕和日志文件）
 */
function logMessage($message, $addTimestamp = true) {
    global $log_file;
    
    $timestamp = $addTimestamp ? '[' . date('Y-m-d H:i:s') . '] ' : '';
    $formattedMsg = $timestamp . $message . PHP_EOL;
    
    // 输出到屏幕
    echo $formattedMsg;
    
    // 写入日志文件
    file_put_contents($log_file, $formattedMsg, FILE_APPEND);
}

/**
 * 获取直播源数据
 */
function fetchLiveSource($url) {
    if (!ini_get('allow_url_fopen')) {
        logMessage('错误: allow_url_fopen 被禁用。请在php.ini中启用它', false);
        die();
    }
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13\r\n",
            'timeout' => 60,
            'ignore_errors' => true
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    
    if ($content === false) {
        $error = error_get_last();
        logMessage("获取数据失败: " . $error['message'], false);
        die();
    }
    
    return $content;
}

/**
 * 解析自定义格式数据
 */
function parseCustomFormat($content) {
    $channels = [];
    $lines = explode("\n", trim($content));
    $currentCategory = '默认分组';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        if (strpos($line, '#genre#') !== false) {
            $parts = explode(',', $line, 2);
            $category = trim($parts[0]);
            
            if (!empty($category)) {
                $currentCategory = $category;
            } else {
                $currentCategory = '默认分组';
            }
            continue;
        }
        
        $parts = explode(',', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $url = trim($parts[1]);
            
            $url = str_replace(['&amp;', '&amp;amp;'], '&', $url);
            $cleanUrl = preg_replace('/\#.*$/i', '', $url);
            
            if (filter_var($cleanUrl, FILTER_VALIDATE_URL) || 
                preg_match('/^(rtmp|rtsp|mms|http|https|ftp):\/\//i', $cleanUrl)) {
                $channels[$cleanUrl] = [
                    'name' => $name,
                    'category' => $currentCategory
                ];
            }
        }
    }
    return $channels;
}

/**
 * 清理旧备份文件
 */
function cleanupOldBackups($dir, $maxFiles) {
    $files = glob($dir . '/live_source_*.txt');
    if (count($files) > $maxFiles) {
        usort($files, function($a, $b) {
            return filemtime($a) < filemtime($b);
        });
        
        foreach (array_slice($files, $maxFiles) as $file) {
            if (is_file($file)) {
                unlink($file);
                logMessage("已删除旧备份文件: " . basename($file));
            }
        }
    }
}

/**
 * 智能分组更新数据库
 */
function smartGroupUpdate($newChannels) {
    global $db_host, $db_user, $db_pass, $db_name, $table_name;
    
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        logMessage("数据库连接失败: " . $conn->connect_error, false);
        die();
    }
    $conn->set_charset("utf8mb4");
    
    // 创建表（如果不存在）
    $createTable = "CREATE TABLE IF NOT EXISTS `$table_name` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `url` VARCHAR(2048) NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        UNIQUE KEY `url_unique` (`url`(1024))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$conn->query($createTable)) {
        logMessage("创建表失败: " . $conn->error, false);
        die();
    }
    
    // 开始事务
    $conn->begin_transaction();
    
    try {
        $stats = [
            'total_new' => count($newChannels),
            'added' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'groups_updated' => 0,
            'groups_unchanged' => 0,
            'groups_skipped' => 0,
            'deleted' => 0
        ];
        
        // 1. 按分组组织新数据
        $groupedNewChannels = [];
        foreach ($newChannels as $url => $channel) {
            $category = $channel['category'];
            $groupedNewChannels[$category][$url] = $channel;
        }
        
        // 2. 获取数据库中的分组和频道
        $groupedCurrentChannels = [];
        $result = $conn->query("SELECT url, name, category FROM `$table_name`");
        while ($row = $result->fetch_assoc()) {
            $category = $row['category'];
            $groupedCurrentChannels[$category][$row['url']] = $row;
        }
        
        // 3. 准备SQL语句
        $insertStmt = $conn->prepare("INSERT INTO `$table_name` (name, url, category) VALUES (?, ?, ?)");
        $updateStmt = $conn->prepare("UPDATE `$table_name` SET name = ?, category = ? WHERE url = ?");
        $deleteStmt = $conn->prepare("DELETE FROM `$table_name` WHERE category = ?");
        
        // 4. 处理每个分组
        foreach ($groupedNewChannels as $category => $newGroupChannels) {
            if (!isset($groupedCurrentChannels[$category])) {
                // 新分组 - 直接插入所有频道
                foreach ($newGroupChannels as $url => $channel) {
                    $insertStmt->bind_param("sss", $channel['name'], $url, $category);
                    if ($insertStmt->execute()) {
                        $stats['added']++;
                    } else {
                        $stats['errors']++;
                        logMessage("插入失败: {$insertStmt->error} [URL: $url]");
                    }
                }
                $stats['groups_updated']++;
                logMessage("新增分组: {$category} (添加 {$stats['added']} 个频道)");
                continue;
            }
            
            $currentGroupChannels = $groupedCurrentChannels[$category];
            $currentUrls = array_keys($currentGroupChannels);
            $newUrls = array_keys($newGroupChannels);
            
            // 检查分组是否需要更新
            $needsFullUpdate = false;
            
            // 检查频道数量变化
            if (count($currentUrls) != count($newUrls)) {
                $needsFullUpdate = true;
            }
            // 检查频道URL集合变化
            else if (array_diff($currentUrls, $newUrls) || array_diff($newUrls, $currentUrls)) {
                $needsFullUpdate = true;
            }
            // 检查频道名称变化
            else {
                foreach ($newGroupChannels as $url => $channel) {
                    if ($currentGroupChannels[$url]['name'] != $channel['name']) {
                        $needsFullUpdate = true;
                        break;
                    }
                }
            }
            
            if ($needsFullUpdate) {
                // 分组有变化 - 先清空再更新
                $deleteStmt->bind_param("s", $category);
                if ($deleteStmt->execute()) {
                    $deletedRows = $conn->affected_rows;
                    $stats['deleted'] += $deletedRows;
                    logMessage("分组[{$category}]已清空旧频道 ({$deletedRows} 条记录)");
                    
                    // 插入新分组所有频道
                    $addedCount = 0;
                    foreach ($newGroupChannels as $url => $channel) {
                        $insertStmt->bind_param("sss", $channel['name'], $url, $category);
                        if ($insertStmt->execute()) {
                            $addedCount++;
                            $stats['added']++;
                        } else {
                            $stats['errors']++;
                            logMessage("插入失败: {$insertStmt->error} [URL: $url]");
                        }
                    }
                    $stats['groups_updated']++;
                    logMessage("分组[{$category}]已更新 (新增 {$addedCount} 个频道)");
                } else {
                    $stats['errors']++;
                    $stats['groups_skipped']++;
                    logMessage("删除失败: {$deleteStmt->error} [分组: $category]");
                }
            } else {
                // 分组无变化 - 跳过更新
                $stats['unchanged'] += count($newGroupChannels);
                $stats['groups_unchanged']++;
                logMessage("分组[{$category}]无变化，跳过更新");
            }
        }
        
        // 5. 处理未在新数据中出现的旧分组
        $existingGroups = array_keys($groupedCurrentChannels);
        $newGroups = array_keys($groupedNewChannels);
        $skippedGroups = array_diff($existingGroups, $newGroups);
        
        $stats['groups_skipped'] += count($skippedGroups);
        foreach ($skippedGroups as $group) {
            logMessage("分组[{$group}]未在新数据中出现，保留原数据");
        }
        
        $insertStmt->close();
        $updateStmt->close();
        $deleteStmt->close();
        
        // 提交事务
        $conn->commit();
        
        return $stats;
        
    } catch (Exception $e) {
        $conn->rollback();
        logMessage("更新失败: " . $e->getMessage(), false);
        die();
    } finally {
        $conn->close();
    }
}

// ================== 主执行流程 ==================

try {
    // 初始化日志文件
    file_put_contents($log_file, "========== 直播源更新开始 ==========\n", FILE_APPEND);
    
    logMessage("正在从数据源获取直播源...");
    $raw_data = fetchLiveSource($source_url);
    
    $backup_filename = 'live_source_'.date('Y-m-d_H-i-s').'.txt';
    file_put_contents($backup_filename, $raw_data);
    logMessage("已保存原始数据备份到: $backup_filename");
    
    cleanupOldBackups($backup_dir, $max_backups);
    
    logMessage("解析直播源数据...");
    $newChannels = parseCustomFormat($raw_data);
    
    if (empty($newChannels)) {
        logMessage("错误: 未解析到有效直播源，请检查数据格式\n备份文件: $backup_filename", false);
        die();
    }
    
    logMessage("成功解析 " . count($newChannels) . " 个频道");
    
    logMessage("开始智能更新数据库...");
    $stats = smartGroupUpdate($newChannels);
    
    // 生成更新报告
    $report = "\n直播源更新完成！\n";
    $report .= "====================\n";
    $report .= "新列表频道数: {$stats['total_new']}\n";
    $report .= "新增频道: {$stats['added']}\n";
    $report .= "删除频道: {$stats['deleted']}\n";
    $report .= "未变化频道: {$stats['unchanged']}\n";
    $report .= "错误记录: {$stats['errors']}\n";
    $report .= "分组更新统计:\n";
    $report .= " - 更新的分组: {$stats['groups_updated']}\n";
    $report .= " - 未变化的分组: {$stats['groups_unchanged']}\n";
    $report .= " - 保留的分组: {$stats['groups_skipped']}\n";
    $report .= "最后更新: " . date('Y-m-d H:i:s') . "\n";
    
    // 验证数据库记录数
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $result = $conn->query("SELECT COUNT(*) AS total FROM $table_name");
    $dbCount = $result ? $result->fetch_assoc()['total'] : '未知';
    $conn->close();
    
    $report .= "数据库当前记录数: $dbCount\n";
    $report .= "========== 直播源更新结束 ==========\n\n";
    
    logMessage($report, false);
    
} catch (Exception $e) {
    logMessage("处理过程中出错: " . $e->getMessage(), false);
    die();
}
?>
