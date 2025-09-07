<?php
/**
 * 沫兮解析接口转缓存本地调用代码 - 模块化重构版
 * 主入口文件，处理所有HTTP请求，协调各个模块工作
 */

// 设置响应头为JSON格式，UTF-8编码
header('Content-Type: application/json; charset=utf-8');

// 引入配置文件 - 包含所有系统设置和常量定义
require_once 'config.php';

// 引入功能模块 - 按依赖顺序加载
require_once 'modules/LicenseValidator.php';    // 许可证验证模块
require_once 'modules/IPAuthorizer.php';        // IP授权验证模块
require_once 'modules/ApiLoader.php';           // API加载器模块
require_once 'modules/CacheManager.php';        // 缓存管理模块
require_once 'modules/DmcaBypasser.php';        // DMCA规避模块
require_once 'modules/ResponseBuilder.php';     // 响应构建模块
require_once 'modules/utils.php';               // 工具函数模块
require_once 'modules/MultiApiProcessor.php';   // 多接口处理器模块
require_once 'modules/Logger.php';              // 日志记录模块
require_once 'modules/ApiHealthChecker.php';    // API健康检查模块
require_once 'modules/RateLimiter.php';         // 限流与频率控制模块

/******************************
 * 辅助函数定义区域
 * 这些函数在主逻辑之前定义，确保可以在主逻辑中使用
 ******************************/

/**
 * 并发API请求函数
 * 使用curl_multi同时请求多个API接口，提高解析速度
 * 
 * @param array $apiList API列表
 * @param array $proxyList 代理列表
 * @param string $targetUrl 目标URL
 * @return array [使用的API, 解析的URL, API响应]
 */
function concurrentApiRequest($apiList, $proxyList, $targetUrl) {
    $multiHandle = curl_multi_init();
    $handles = [];
    
    // 创建所有请求句柄
    foreach ($apiList as $index => $api) {
        $urlWithParams = $api . urlencode($targetUrl);
        $handles[$index] = curl_init();
        
        // 设置基本cURL选项
        DmcaBypasser::setCurlOptions($handles[$index], $urlWithParams);
        
        // 设置DMCA规避选项
        DmcaBypasser::setBypassOptions($handles[$index], $targetUrl);
        
        // 设置代理（如果启用）
        if (USE_PROXY && !empty($proxyList)) {
            $proxy = $proxyList[array_rand($proxyList)];
            curl_setopt($handles[$index], CURLOPT_PROXY, $proxy);
        }
        
        curl_multi_add_handle($multiHandle, $handles[$index]);
    }
    
    // 执行并发请求
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle); // 等待活动连接
    } while ($running > 0);
    
    // 处理响应
    $usedApi = null;
    $parsedUrl = null;
    $apiResponse = null;
    
    foreach ($handles as $index => $handle) {
        $api = $apiList[$index];
        $response = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        
        // 检查HTTP状态码和cURL错误
        if ($curlError || $httpCode != 200) {
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
            continue;
        }
        
        // 尝试解析JSON
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
            continue;
        }
        
        // 检查是否有DMCA错误
        if (DmcaBypasser::hasDmcaError($decodedResponse)) {
            if (DEBUG_MODE) {
                error_log("API返回DMCA错误: $api");
            }
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
            continue;
        }
        
        // 增强的URL字段检测 - 尝试多个可能的字段名
        $possibleFields = ['url', 'm3u8', 'play_url', 'src', 'address'];
        $foundUrl = null;
        foreach ($possibleFields as $field) {
            if (isset($decodedResponse[$field]) && !empty($decodedResponse[$field])) {
                $foundUrl = $decodedResponse[$field];
                break;
            }
        }
        
        if ($foundUrl === null) {
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
            continue;
        }
        
        // 成功获取到URL，记录并跳出循环
        $usedApi = $api;
        $parsedUrl = $foundUrl;
        $apiResponse = $decodedResponse;
        
        // 清理其他句柄
        foreach ($handles as $h) {
            curl_multi_remove_handle($multiHandle, $h);
            curl_close($h);
        }
        curl_multi_close($multiHandle);
        break;
    }
    
    // 如果没有找到有效响应，清理资源
    if ($parsedUrl === null) {
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }
        curl_multi_close($multiHandle);
    }
    
    return [$usedApi, $parsedUrl, $apiResponse];
}

/**
 * 顺序API请求函数
 * 依次尝试API列表中的每个接口，直到找到可用的接口
 * 
 * @param array $apiList API列表
 * @param array $proxyList 代理列表
 * @param string $targetUrl 目标URL
 * @return array [使用的API, 解析的URL, API响应]
 */
function sequentialApiRequest($apiList, $proxyList, $targetUrl) {
    $usedApi = null;
    $parsedUrl = null;
    $apiResponse = null;
    
    foreach ($apiList as $api) {
        $urlWithParams = $api . urlencode($targetUrl);
        $ch = curl_init();
        
        // 设置基本cURL选项
        DmcaBypasser::setCurlOptions($ch, $urlWithParams);
        
        // 设置DMCA规避选项
        DmcaBypasser::setBypassOptions($ch, $targetUrl);
        
        // 设置代理（如果启用）
        if (USE_PROXY && !empty($proxyList)) {
            $proxy = $proxyList[array_rand($proxyList)];
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 检查HTTP状态码和cURL错误
        if ($curlError || $httpCode != 200) {
            // 记录错误，继续尝试下一个
            if (DEBUG_MODE) {
                error_log("API请求失败: $urlWithParams, HTTP状态码: $httpCode, cURL错误: $curlError");
            }
            continue;
        }

        // 尝试解析JSON
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 记录JSON解析错误
            if (DEBUG_MODE) {
                error_log("API返回非JSON: $urlWithParams, 错误: " . json_last_error_msg());
            }
            continue;
        }
        
        // 检查是否有DMCA错误
        if (DmcaBypasser::hasDmcaError($decodedResponse)) {
            if (DEBUG_MODE) {
                error_log("API返回DMCA错误: $urlWithParams");
            }
            continue;
        }

        // 增强的URL字段检测 - 尝试多个可能的字段名
        $possibleFields = ['url', 'm3u8', 'play_url', 'src', 'address'];
        $foundUrl = null;
        foreach ($possibleFields as $field) {
            if (isset($decodedResponse[$field]) && !empty($decodedResponse[$field])) {
                $foundUrl = $decodedResponse[$field];
                break;
            }
        }

        if ($foundUrl === null) {
            if (DEBUG_MODE) {
                error_log("API返回中未找到有效URL字段: $urlWithParams");
            }
            continue;
        }

        // 成功获取到URL，记录并跳出循环
        $usedApi = $api;
        $parsedUrl = $foundUrl;
        $apiResponse = $decodedResponse; // 保存整个响应
        break;
    }
    
    return [$usedApi, $parsedUrl, $apiResponse];
}

/******************************
 * 主逻辑流程
 * 处理请求的核心业务流程
 ******************************/

// 初始化日志模块
if (LOG_ENABLED) {
    Logger::init();
}

// 初始化限流模块
if (RATE_LIMIT_ENABLED) {
    RateLimiter::init();
    
    // 设置限流规则
    RateLimiter::setRules([
        'max_requests_per_minute' => MAX_REQUESTS_PER_MINUTE,
        'max_requests_per_hour' => MAX_REQUESTS_PER_HOUR,
        'max_requests_per_day' => MAX_REQUESTS_PER_DAY,
        'ban_duration' => BAN_DURATION
    ]);
    
    // 检查请求频率
    $clientIp = $_SERVER['REMOTE_ADDR'];
    list($allowed, $error) = RateLimiter::checkRequest($clientIp);
    
    if (!$allowed) {
        echo json_encode(
            ResponseBuilder::buildErrorResponse("请求过于频繁: $error", 429),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

// 1. 验证许可证 - 确保软件合法使用
if (!LicenseValidator::validate()) {
    echo json_encode(
        LicenseValidator::getErrorResponse(), 
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// 2. 验证IP授权 - 检查客户端IP是否在授权列表中
$ipAuthResult = IPAuthorizer::validate();
if ($ipAuthResult !== true) {
    echo json_encode(
        $ipAuthResult, 
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// 3. 检查URL参数 - 确保请求包含有效的URL参数
if (!isset($_GET['url']) || empty($_GET['url'])) {
    echo json_encode(
        ResponseBuilder::buildErrorResponse('Error: 无URL参数', 400),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// 4. 加载API列表和代理列表
$apiList = ApiLoader::loadApiList();
$proxyList = ApiLoader::loadProxyList();

// 在加载API列表后添加健康检查过滤
if (HEALTH_CHECK_ENABLED) {
    $apiList = ApiHealthChecker::filterUnhealthyApis($apiList);
    if (DEBUG_MODE && LOG_ENABLED) {
        Logger::debug("健康检查过滤后的API列表", ['apis' => $apiList]);
    }
}

// 5. 初始化缓存目录
CacheManager::initCacheDir();

// 6. 处理请求
$targetUrl = $_GET['url'];

// 检查缓存 - 如果已有缓存，直接返回缓存结果
$cacheResponse = CacheManager::checkCache($targetUrl);
if ($cacheResponse !== false) {
    echo json_encode(
        $cacheResponse, 
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// 记录最终使用的API
$usedApi = null;
// 记录解析到的URL
$parsedUrl = null;
// 记录API返回的原始数据
$apiResponse = null;
// 记录调试信息
$debugInfo = [];

// 使用多接口处理器进行API调用
list($usedApi, $parsedUrl, $apiResponse, $debugInfo) = 
    MultiApiProcessor::process($apiList, $proxyList, $targetUrl);

// 如果所有接口都无法解析
if ($parsedUrl === null) {
    $errorData = [
        'apis_tried' => count($apiList),
        'download_msg' => '解析失败，无法获取播放地址'
    ];
    
    // 调试模式下显示更多信息
    if (DEBUG_MODE) {
        $errorData['api_list'] = $apiList;
        $errorData['debug_info'] = $debugInfo;
    }
    
    // 记录错误日志
    if (LOG_ENABLED) {
        Logger::error("所有接口均无法解析", [
            'target_url' => $targetUrl,
            'apis_tried' => count($apiList),
            'debug_info' => $debugInfo
        ]);
    }
    
    echo json_encode(
        ResponseBuilder::buildErrorResponse('所有接口均无法解析', 500, $errorData),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// 记录API调用统计
if (LOG_ENABLED) {
    // 这里应该计算响应时间，但需要修改MultiApiProcessor以返回响应时间
    Logger::logApiCall($usedApi, true, 0); // 暂时使用0作为响应时间
}

// 下载状态消息
$downloadMsg = '';
// 返回的URL
$returnUrl = $parsedUrl;
// 缓存文件路径
$cacheFile = null;

// 如果下载功能启用，则尝试下载文件
if (DOWNLOAD_ENABLED && CACHE_ENABLED) {
    $fileContent = DmcaBypasser::downloadFile($parsedUrl); // 下载文件内容
    
    if ($fileContent !== false) {
        $cacheFile = CacheManager::saveCache($targetUrl, $fileContent); // 保存文件内容到缓存
        
        if ($cacheFile) {
            // 下载并保存成功
            $downloadMsg = '文件下载成功并已缓存';
            $returnUrl = get_current_url() . $cacheFile;
            
            // 记录日志
            if (LOG_ENABLED) {
                Logger::info("文件下载成功并已缓存", [
                    'target_url' => $targetUrl,
                    'cache_file' => $cacheFile
                ]);
            }
        } else {
            // 如果保存缓存失败
            $downloadMsg = '解析成功，但缓存保存失败，已返回API URL';
            
            // 记录日志
            if (LOG_ENABLED) {
                Logger::warn("缓存保存失败", [
                    'target_url' => $targetUrl,
                    'parsed_url' => $parsedUrl
                ]);
            }
        }
    } else {
        // 如果下载文件失败
        $downloadMsg = '解析成功，但文件下载失败，已返回API URL';
        
        // 记录日志
        if (LOG_ENABLED) {
            Logger::warn("文件下载失败", [
                'target_url' => $targetUrl,
                'parsed_url' => $parsedUrl
            ]);
        }
    }
} else {
    // 根据设置生成状态消息
    if (!DOWNLOAD_ENABLED) {
        $downloadMsg = '解析成功，下载功能未启用';
    } elseif (!CACHE_ENABLED) {
        $downloadMsg = '解析成功，缓存功能未启用';
    } else {
        $downloadMsg = '解析成功';
    }
    
    // 记录日志
    if (LOG_ENABLED) {
        Logger::info("解析成功", [
            'target_url' => $targetUrl,
            'parsed_url' => $parsedUrl,
            'used_api' => $usedApi
        ]);
    }
}

// 返回结果
$responseData = ResponseBuilder::buildSuccessResponse(
    $returnUrl, $parsedUrl, $targetUrl, $downloadMsg, $usedApi, $cacheFile
);

// 在调试模式下添加接口尝试信息
if (DEBUG_MODE) {
    $responseData['debug'] = [
        'api_attempts' => $debugInfo,
        'total_apis' => count($apiList),
        'apis_tried' => count($debugInfo)
    ];
}

// 输出JSON响应
echo json_encode(
    $responseData, 
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);