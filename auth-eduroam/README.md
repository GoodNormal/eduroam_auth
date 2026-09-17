# auth-eduroam

通过 Eduroam 来登录皮肤站。

认证服务使用 [北京大学 eduroam 探测点](https://analysis.eduroam.edu.cn/checkc/pkudetection)，协议为 PEAPv0/EAP-MSCHAPv2。插件先获取会话 Cookie 和 CSRF 令牌，再向该站点的 `/checkc/peapmschap` 接口提交凭据。仅在接口明确返回成功且 EAP 日志确认认证完成时允许登录。

部署时替换整个 `auth-eduroam` 目录（包括新增的 `src/Authenticator.php`）。服务器需要能通过 HTTPS 访问 `analysis.eduroam.edu.cn`；连接超时为 10 秒，单次请求超时为 30 秒。无法连接或响应异常时会返回认证失败。上游诊断日志可能包含密码，插件不会将其记录或返回前端。

## 功能

- [x] 验证 Eduroam 登录凭据
- [x] 使用 Blessing Skin 验证码或 reCAPTCHA
- [x] 替换邮箱域名
- [ ] 设置密码

> 使用 require-passsword 插件来让用户重置密码，以用于外置登录等。

## 配置

在 .env 文件中设置环境变量。

`EDUROAM_HOST` : 添加在用户名后的 Eduroam 域名。

`EDUROAM_STORE_HOST` : 替换 `EDUROAM_HOST` 并存储在数据库中的电子邮箱域名。

## 示例

不设置任何环境变量以允许所有 Eduroam 成员。

```
(不设置)
```

设置 `EDUROAM_HOST=example.com` (不加 `@`) 可在 Eduroam 用户名后自动添加 `@example.com` 。这样，只有指定成员才能成功登录。

```
EDUROAM_HOST=example.com
```

同时设置 `EDUROAM_HOST=example.com` 和 `EDUROAM_STORE_HOST=mail.example.com` (均不加 `@`) 可在 Eduroam 用户名后自动添加 `@example.com` ，但使用 `username@mail.example.com` 在数据库中查找或注册用户。在学校 Eduroam 域名与电子邮件不一样时尤为有用。

```
EDUROAM_HOST=example.com
EDUROAM_STORE_HOST=mail.example.com
```

## 开发验证

在具有 Laravel 8 HTTP Client 和 Guzzle 7 的环境中，运行 `php auth-eduroam/tests/authenticator.php /path/to/vendor/autoload.php`（可使用 Blessing Skin 的 Composer 自动加载文件）。测试使用模拟响应，不会向认证站点发送凭据，覆盖会话/CSRF、特殊字符编码、成功与失败结果、超时及异常响应。

## 声明

本项目仅用于学习相关验证过程。使用请自担风险。作者不承担使用此插件带来的任何损失或引发的责任。

## 参见

https://github.com/bs-community/blessing-skin-server

https://github.com/bs-community/blessing-skin-plugins

---

# auth-eduroam

Log in skin server with Eduroam.

Authentication uses the [Peking University eduroam detection site](https://analysis.eduroam.edu.cn/checkc/pkudetection) with PEAPv0/EAP-MSCHAPv2. The plugin obtains session cookies and a CSRF token before posting credentials to `/checkc/peapmschap`. Login requires both an explicit success result and an EAP authentication success log entry.

Deploy the entire `auth-eduroam` directory, including the new `src/Authenticator.php`. The server must reach `analysis.eduroam.edu.cn` over HTTPS. Connection and per-request timeouts are 10 and 30 seconds respectively; connection errors and invalid responses reject authentication. Upstream diagnostics may contain passwords and are never logged or returned to the browser by the plugin.

## Features

- [x] Verify Eduroam credentials
- [x] Use Blessing Skin (re)CAPTCHAs
- [x] Replace Email hosts
- [ ] Set Passwords

> Use require-passsword plugin to let users reset passwords for external authentication, etc.

## Configuration

Set environment variables in .env file.

`EDUROAM_HOST` : Eduroam hostname that will append to username.

`EDUROAM_STORE_HOST` : Email hostname that will store in database in replace of `EDUROAM_HOST` .

## Examples

Leave environment variables unset to allow all Eduroam members.

```
(None)
```

Set `EDUROAM_HOST=example.com` (without `@`) to automatically append `@example.com` to Eduroam username. Therefore only specified members can log in successfully.

```
EDUROAM_HOST=example.com
```

Set `EDUROAM_HOST=example.com` and `EDUROAM_STORE_HOST=mail.example.com` (without `@`) together to append `@example.com` to Eduroam username, but use `username@mail.example.com` to lookup or register users in database. Useful if your school has different hostname for Eduroam and student email.

```
EDUROAM_HOST=example.com
EDUROAM_STORE_HOST=mail.example.com
```

## Development checks

With Laravel 8's HTTP Client and Guzzle 7 available, run `php auth-eduroam/tests/authenticator.php /path/to/vendor/autoload.php`, using Blessing Skin's Composer autoloader if available. Tests use simulated responses without sending credentials to the remote site, covering sessions/CSRF, form encoding, success/failure handling, timeouts, and malformed responses.

## Statement

This project is only for studying relevant authentication process. USE IT AT YOUR OWN RISK. The author assumes no responsibility for any losses, damages, or liabilities arising from the use of this project.

## See also

https://github.com/bs-community/blessing-skin-server

https://github.com/bs-community/blessing-skin-plugins
