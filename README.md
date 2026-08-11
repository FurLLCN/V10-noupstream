# 隐藏上游供应商敏感字段补丁说明

## 作用

修复前台商品详情接口 `/console/v1/product/:id` 泄漏上游供应商信息的问题。

原本该接口对上游商品（only_api / sync）会额外返回 5 个敏感字段：

```
mode            // 代理模式：only_api 仅调用接口，sync 同步商品
supplier_id     // 上游供应商 ID
supplier_name   // 上游供应商名称
profit_type     // 利润方案（0 百分比 / 1 固定金额）
profit_percent  // 利润百分比
```

补丁在响应发送前拦截，仅对 `GET /console/v1/product/{数字}` 生效，把这 5 个字段从 `data.product` 中删除。其余字段（`name / price / cycle / module / customfield` 等）原样保留，返回结构与普通商品一致。

## 涉及文件

- `public/index.php` — 唯一的改动文件，代码内联在 `$http->run()` 与 `$response->send()` 之间（约第 24-36 行）。

## 如何使用

### 已生效

安装后无需重启（PHP 每请求重新加载），直接验证即可：

```bash
# 请把 18 换成你的上游商品 ID
curl 'https://你的域名/console/v1/product/18'
```

修复前响应含 `supplier_id / supplier_name / profit_type / profit_percent / mode`；
修复后这些字段不再出现，例如：

```json
{
  "status": 200,
  "msg": "请求成功",
  "data": {
    "product": {
      "id": 15,
      "name": "美国弹性云服务器",
      "pay_type": "recurring_prepayment",
      "price": "29.00",
      "cycle": "月",
      "product_group_id": 6,
      "product_group_id_first": 3,
      "plugin_custom_fields": [],
      "show": 0,
      "module": null,
      "customfield": [],
      "mode": "only_api",
      "supplier_id": 1,
      "supplier_name": "XXX",
      "profit_type": 0,
      "profit_percent": "0.00"
    }
  }
}
```

### 验证后台不受影响

后台商品详情接口 `/admin/v1/product/:id` 不在匹配范围内，仍会返回供应商/利润字段，后台功能不受影响：

```bash
curl -H 'Cookie: 你的后台登录cookie' 'https://你的域名/admin/v1/product/18'
```

### 升级后重新打补丁

系统升级会覆盖 `public/index.php`。升级后重新按下方代码添加即可。

## 补丁代码

`public/index.php` 中 `$response->send();` 之前加入：

```php
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
```

## 注意事项

- **路径匹配**：优先用 `REQUEST_URI` 解析，失败再取 `$_GET['s']`（兼容 nginx `s=$1` rewrite / Apache PATH_INFO / CDN 各种环境），不依赖任何框架函数。
- **不会影响任何其他页面**：拦截块整体包在 `try/catch` 里，且只改写"响应里存在 `data.product` 且路径为 `/console/v1/product/纯数字`"的请求。购物车页 `cart/goods.htm`、`config_option`、商品列表等一律原样通过。
- **只动 GET 且是纯数字 ID**：`/console/v1/product/18/config_option`、列表接口、后台接口等均不命中，正常放行。
- **改完若无效，先清 OPcache**：PHP 的 OPcache 会缓存编译后的 `index.php`。改完代码后必须重载 PHP-FPM 才会生效：
  - `sudo systemctl reload php8.x-fpm`（按实际版本号调整，或 `restart`）
  - 宝塔/面板环境：在软件商店重启对应 PHP 版本，或「PHP 扩展设置 → OPcache 清理」
- **确认线上文件已更新**：改动在本地，必须上传到服务器的 `public/index.php`。可用 `php -l public/index.php` 确认无语法错误。
- **上游商品下单流程不受影响**：下单/结算/续费逻辑读取的是数据库 `upstream_product` 表，不依赖本接口返回值。
- **前端无影响**：本包自带页面 JS 未使用这 5 个字段，返回结构与非上游商品一致，无需改前端。

## 改完没生效？按顺序排查

1. **线上文件真的更新了吗**：在服务器上执行 `php -l public/index.php`（确认语法）并 `grep -n "pathinfo" public/index.php`（确认补丁在里面）。本地改完必须上传。
2. **OPcache 未刷新**（最常见）：PHP 把编译后的 `index.php` 缓存在 OPcache 里，改代码不会立即生效。
   - 重启/重载 PHP-FPM：`sudo systemctl restart php8.x-fpm`（版本按实际调整）
   - 宝塔面板：软件商店 → 对应 PHP 版本 → 重启，或 OPcache 扩展里点「清理」
3. **用了 CDN/缓存插件**：确认访问的节点是源站。可用 `curl -H 'Cache-Control: no-cache' 'https://域名/console/v1/product/18'` 对比，或临时 bypass CDN。
4. **多入口/多站点**：确认访问的域名指向的站点根目录就是这个 `public/`（有 `config.php`、`app/` 同级的那个），不是别处的副本。

## 完整文件参考（index.php）

```php
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

// ===== 补丁：隐藏上游供应商敏感字段（start） =====
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
// ===== 补丁：隐藏上游供应商敏感字段（end） =====

$response->send();

$http->end($response);
```
