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

## 5. Minimal header set (fresh browser session, working `sign`)

Starting from a browser request that returns 200, headers were removed one at a
time. The endpoint kept returning 200 with the full profile through every drop:

```
drop x-hash                          -> 200
drop x-hash + x-of-rev               -> 200
drop csrf/lang cookies (sess only)   -> 200
drop ALL cookies                     -> 200
```

So the profile endpoint requires only: `app-token`, a valid `sign` (from
current rules), `time`, `x-bc`, and a `User-Agent`. It does **not** require
`x-hash`, `x-of-rev`, a session cookie, or any Cloudflare cookie.

**This settles the diagnosis.** The 400 in sections 1-3 was caused solely by
stale signing rules. `x-hash` is not enforced here, so there is nothing to
reverse-engineer, and the direct adapter's design (signed, anonymous, no
cookies) is already correct. It needs only current rules.

## 6. How the live build actually signs (browser inspection of build 202609171554)

Loaded onlyfans.com in a real browser (passes the Cloudflare homepage
challenge), read the 121 loaded JS chunks and the app config. Findings:

- **The signing rules are NOT baked into the JS.** Neither the live prefix
  (`65335`), the suffix (`6aac0d65`), nor any `static_param` / `checksum_indexes`
  literal appears in any of the 121 chunks. Only the constant `app-token`
  (`33d57ade...`) is a literal (in `app.js`). This is the key difference from
  the old community-file model, which assumed the rules sit in the bundle.
- **The header set is assembled at runtime** by an obfuscated function. Decoded
  from `app.js`:
  - `x-bc`   <- GET `https://cdn2.onlyfans.com/key/`  (`VUE_APP_BC_REQUEST_URL`)
  - `x-hash` <- GET `https://cdn2.onlyfans.com/hash/?u=<userId>` (`VUE_APP_HASH_REQUEST_URL`)
  - `app-token` = constant, `x-of-rev` = build id, `user-id` only when logged in.
  - `sign` + `time` are produced by an in-app function (`J.A`) whose constants
    are string-obfuscated, so they never appear as plain text.
- **Both runtime endpoints work browser-free** (plain `curl`, no cookies):
  ```
  GET cdn2.onlyfans.com/key/        -> 200  f519e5da...  (40 hex, the x-bc value)
  GET cdn2.onlyfans.com/hash/?u=0   -> 200  QIzeaS3W...  (40 char, the x-hash value)
  ```

**What this means for the last mile.** `x-bc` and `x-hash` are solved: fetch
them. `x-hash` is not even enforced on the profile endpoint (section 5). The
only remaining piece is the `sign`, and its constants are not extractable as
plain text; reproducing it browser-free means lifting the obfuscated `sign`
function out of the bundle and running it in a JS sandbox (Node) once per build.
That is the extraction work a managed provider does, and it was not built here.

## Conclusion

The published signing rules lagged a web build rotation (build stamped
2026-09-17). The last mile is keeping rules, `x-hash` and the `fp`/`x-bc` pair
current with each rotation, not a browser. That was not built; see the README
section "Direct OnlyFans, the default" for why.
