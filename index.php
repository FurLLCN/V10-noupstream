<?php

// [ 应用入口文件 ]
namespace think;

if (!file_exists(__DIR__ . '/../config.php')){
    header("location:/install/index.html");die;
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';

define('IDCSMART_ROOT',dirname(__DIR__ ). '/'); # 网站根目录
define('WEB_ROOT',__DIR__ . '/'); # 网站入口目录
define('UPLOAD_DEFAULT',__DIR__ . '/upload/common/default/'); # 文件保存默认路径

// 执行HTTP应用并响应
$App=new App();
$App->debug(APP_DEBUG);
$http = $App->http;

$response = $http->run();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $response instanceof Response) {
        $path = ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if (!preg_match('#^console/v1/product/\d+$#', $path)) {
            $path = ltrim($_GET['s'] ?? '', '/');
        }
        if (preg_match('#^console/v1/product/\d+$#', $path)) {
            $data = $response->getData();
            if (is_array($data) && isset($data['data']['product']) && is_array($data['data']['product'])) {
                foreach (['mode', 'supplier_id', 'supplier_name', 'profit_type', 'profit_percent'] as $field) {
                    unset($data['data']['product'][$field]);
                }
                $response->data($data);
            }
        }
    }
} catch (\Throwable $e) {
}

$response->send();

$http->end($response);
