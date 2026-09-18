# Direct route diagnosis

Probe run 2026-09-19 from this machine, anonymous, one public profile
(`madison420ivy`). Cookie values, `sign` hashes, `x-hash` and device ids are
redacted; only the parts that matter for the comparison are kept.

## 1. HTML homepage, plain HTTP client

```
GET https://onlyfans.com/
HTTP/2 403   server: cloudflare
set-cookie: __cf_bm
<title>Just a moment...</title>   cType: 'managed'
```

Cloudflare managed challenge. No `sess`, `csrf` or `fp` cookie.

## 2. Signed API calls, our signer, shared cookie jar

Rules from `datawhores/onlyfans-dynamic-rules`: prefix `26974`, suffix `669fb034`.

```
GET /api2/v2/users/madison420ivy -> 400   server: cloudflare
  set-cookie: sess, __cf_bm, _cfuvid
  {"error":{"code":401,"message":"Please refresh the page"}}
GET /api2/v2/init               -> 400   (same body, sess sent)
GET /api2/v2/users/madison420ivy -> 400   (same body, sess sent)
```

The API path is not challenged: the OnlyFans application answers in JSON and
issues an anonymous `sess` without a page load. The session is not what fails.

## 3. Logged-out browser request that returns 200

Brave 151 on Linux, private window, copied from DevTools.

| Header / cookie | Browser (200) | Adapter (400) |
| --- | --- | --- |
| `sign` prefix / suffix | `65335` / `6aac0d65` | `26974` / `669fb034` |
| `app-token` | `33d57ade...` | `33d57ade...` (same) |
| `x-of-rev` | `202609171554-a5a528bc87` | not sent |
| `x-hash` | sent | not sent |
| `x-bc` | equal to the `fp` cookie | random, no `fp` cookie |
| `user-id` | not sent | `0` |
| Cookies | `sess`, `fp`, `csrf`, `lang`, `__cf_bm`, `_cfuvid` | `sess`, `__cf_bm`, `_cfuvid` |

Response: the profile object, unwrapped, `id` 5140520, `favoritedCount`
606793, `favoritesCount` 16, `subscribersCount` null. Same shape the
normalizer already accepts for source `onlyfans`.

## 4. Static script files, plain HTTP

```
GET https://static2.onlyfans.com/static/prod/f/202609171554-a5a528bc87/2313.js
(no cookies, no browser headers, user agent only)
200  text/javascript; charset=utf-8  9980 bytes
```

The static server is not behind the Cloudflare challenge, and the build folder
is the `x-of-rev` value. A rules job can download the client code without a
browser once it knows the current build and file names; only discovering those
might need a page load, once per release.

## Conclusion

The published signing rules lagged a web build rotation (build stamped
2026-09-17). The last mile is keeping rules, `x-hash` and the `fp`/`x-bc` pair
current with each rotation, not a browser. That was not built; see the README
section "Direct OnlyFans, the default" for why.
