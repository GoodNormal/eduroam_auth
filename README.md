# Eduroam 登录插件

为 **Blessing Skin 6.x** 皮肤站添加 Eduroam 账号登录。认证成功后，插件会按邮箱查找已有用户；没有对应用户时自动创建账号并登录。

本项目包含两个插件：

| 目录 | 后台名称 | 用途 |
| --- | --- | --- |
| `auth-eduroam` | 使用 Eduroam 登录 | 必装，提供 Eduroam 登录入口和主备认证线路。 |
| `require-password` | 要求设置密码 | 可选，引导本地密码为空的用户设置皮肤站密码。 |

## 安装

1. 准备已正常运行的 Blessing Skin 6.x 皮肤站。PHP 需要启用 DOM/XML 扩展，服务器需要能通过 HTTPS 访问两条认证线路。
2. 将本项目中的 `auth-eduroam` 文件夹完整复制到皮肤站的 `plugins` 目录中。需要设置本地密码功能时，再复制 `require-password` 文件夹。
3. 用管理员账号进入皮肤站后台，在「插件管理」中启用「使用 Eduroam 登录」，按需启用「要求设置密码」。
4. 按下文配置账号域名，然后退出当前登录，在登录页选择「通过 Eduroam 账号登录」。

默认安装后的目录结构如下：

```text
blessing-skin/
├── .env
├── artisan
├── vendor/
└── plugins/
    ├── auth-eduroam/
    │   ├── package.json
    │   ├── bootstrap.php
    │   ├── src/
    │   │   ├── Authenticator.php
    │   │   └── LoginController.php
    │   └── ...
    └── require-password/       # 可选
        ├── package.json
        ├── bootstrap.php
        └── src/
```

如果皮肤站配置了 `PLUGINS_DIR`，请使用对应的插件目录；该配置的含义见 [Blessing Skin 配置文档](https://blessing.netlify.app/env)。每个插件的 `package.json` 应直接位于自己的目录内，避免多嵌套一层仓库文件夹。

插件依赖皮肤站自带的 PHP 依赖，安装插件无需单独运行 `npm install` 或 `composer install`。

## 配置账号域名

配置写在 **皮肤站根目录的 `.env` 文件**中。插件后台配置页仅显示当前配置，不提供保存表单。

### 方式一：允许用户输入完整 Eduroam 账号

不设置 `EDUROAM_HOST`；如果已经添加过，删除或注释该行，不要只把值留空。

登录示例：

```text
用户名：student@example.edu.cn
密码：该账号的 Eduroam 密码
```

这种方式不限定学校域名，能否认证取决于学校和上游线路对 PEAP/MSCHAPv2 的支持。

### 方式二：自动添加指定学校的域名

在 `.env` 中添加：

```dotenv
EDUROAM_HOST=example.edu.cn
```

将 `example.edu.cn` 替换为学校实际使用的 Eduroam 账号域名，不带 `@`，也不一定与学校邮箱域名相同。

此时用户只填写 `student`，插件会使用 `student@example.edu.cn` 认证，并按这个邮箱查找或创建皮肤站用户。不要再输入完整账号，否则域名会被重复追加。

如果修改后没有生效，在皮肤站根目录执行：

```bash
php artisan config:clear
```

### `EDUROAM_STORE_HOST` 的当前限制

该选项原本用于将认证域名替换成另一个入库邮箱域名，但**当前登录代码没有使用它的值**。即使配置了它，仍按认证使用的完整账号查找或创建用户。请暂时不要依赖此选项做邮箱映射。

现有用户按邮箱匹配。更改账号域名不会自动迁移旧用户，也不会合并账号；如果生成了不同邮箱，下次认证成功可能会创建新用户。

## 用户如何登录

1. 打开皮肤站登录页，选择「通过 Eduroam 账号登录」，也可直接访问站点的 `/auth/eduroam/login`。
2. 按站点配置输入完整账号或用户名，并填写 Eduroam 密码。
3. 认证成功后进入用户中心；首次登录会自动创建用户，已有用户按邮箱匹配登录。
4. 连续失败多次后，按页面提示填写验证码。插件复用皮肤站的验证码或 reCAPTCHA 配置。

Eduroam 密码用于远程认证，不会作为皮肤站本地密码保存。新建用户的本地密码为空，且会被标记为已验证。

## 主备线路如何切换

| 优先级 | 认证站点 | 行为 |
| --- | --- | --- |
| 主线路 | [北京大学 Eduroam 探测点](https://analysis.eduroam.edu.cn/checkc/pkudetection) | 每次登录优先使用，获取会话和 CSRF 令牌后进行 PEAP/MSCHAPv2 认证。 |
| 备用线路 | [Seesea Eduroam 测试站](https://eduroam.seesea.site/) | 主线路无法完成认证时自动尝试一次，读取其 PEAP/MSCHAPv2 测试结果。 |

- 主线路连接失败、超时、HTTP 错误、缺少 CSRF 令牌或响应格式异常时，自动切换备用线路。
- 主线路明确返回凭据错误时，直接提示失败，不切换备用线路。
- 两条线路都不可用时拒绝登录。下一次登录仍从主线路开始，不会永久切换到备用线路。

主备切换无需额外配置。每个请求的连接超时为 10 秒，总超时为 30 秒。完整流程最多顺序执行三次请求，最坏约需 90 秒；PHP、Web 服务器和反向代理的请求时限应覆盖这段时间。

账号和密码会通过 HTTPS 发送到实际使用的认证站点。两站之间不共享 Cookie 或 CSRF 令牌，插件不会记录或向前端返回上游原始诊断日志。

## 设置皮肤站本地密码（可选）

如果希望通过 Eduroam 创建的用户设置皮肤站密码，启用 `require-password` 插件：

1. 用户通过 Eduroam 认证并进入用户中心。
2. 插件发现本地密码为空后，会退出当前登录并跳转到密码重置页面。
3. 用户按页面提示设置皮肤站密码，之后可以使用本地账号密码登录。

这不会修改学校的 Eduroam 密码。需要 Minecraft 外置登录时，还应配置相应的外置认证插件；本项目只负责 Eduroam 登录和本地密码引导。

## 更新插件

将新版完整的 `auth-eduroam` 目录替换到皮肤站对应的插件目录中，保留皮肤站自己的 `.env` 配置。确保包含 `src/Authenticator.php`，只替换控制器无法完成认证。

更新模板后若页面仍显示旧内容，可在皮肤站根目录执行 `php artisan view:clear`。

## 常见问题

| 现象 | 检查方法 |
| --- | --- |
| 后台找不到插件 | 检查目录位置、文件读取权限，以及 `package.json` 是否多嵌套了一层。 |
| 登录页没有 Eduroam 入口 | 确认「使用 Eduroam 登录」已启用，退出管理员登录后再试。 |
| 提示账号或密码错误 | 确认使用 Eduroam 凭据，并检查是否按 `EDUROAM_HOST` 的配置填写了用户名。 |
| 认证失败或等待很久 | 检查服务器访问两个认证站点的 DNS、HTTPS 连通性、证书信任和请求超时。备用站首页可访问不代表其认证接口可用。 |
| 登录后跳到重置密码页面 | 启用了 `require-password` 且本地密码为空，按提示设置即可。 |
| 修改 `.env` 后未生效 | 执行 `php artisan config:clear`，并在插件配置页核对显示的域名。 |
| 配置了 `EDUROAM_STORE_HOST` 但邮箱没变 | 这是当前实现的已知限制，参见上面的配置说明。 |

## 开发验证

在皮肤站根目录运行：

```bash
php plugins/auth-eduroam/tests/authenticator.php vendor/autoload.php
```

如使用自定义插件目录，请调整第一个路径。测试依赖 Laravel 8 HTTP Client 和 Guzzle 7，使用模拟响应验证认证结果、Cookie/CSRF、请求编码和主备切换，不向真实认证站点发送账号密码。自动检查通过不代表上游服务当前可用，部署后仍需用测试账号验证登录。

认证接口细节见 [auth-eduroam 说明](auth-eduroam/README.md)，密码引导插件见 [require-password 说明](require-password/README.md)。
