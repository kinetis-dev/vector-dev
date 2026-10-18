# Configuration

## Environment keys

| Key | Meaning |
|---|---|
| `VECTOR_DB_CONNECTION` | The connection Vector's own tables live on; `default` when unset. See {doc}`databases`. |
| `VECTOR_QUEUE` | The queue name Vector's jobs are pushed to; `default` when unset. |
| `VECTOR_QUEUE_CONNECTION` | A named queue connection for Vector's jobs; its backend is `QUEUE_<NAME>_CONNECTION`. Unset, the application's queue. |
| `VECTOR_STORAGE_CONNECTION` | A named storage connection (`FILESYSTEM_<NAME>_*`) for uploads. Unset, the application's `FilesystemOperator` (`FILESYSTEM_DRIVER`). See {doc}`files`. |
| `VECTOR_CURRENCY` | The ISO 4217 code a `Currency` field starts from when it declares no `default()` (and allows the code). An invalid code stops the boot. |
| `VECTOR_EXPORT_FORMATS` | The export formats offered, `csv,xlsx` by default. CSV is always offered; XLSX only where `openspout/openspout` and PHP's `zip` extension are installed and a queue runs Vector's jobs. An unknown format stops the boot. |
| `VECTOR_UI_DIR` | Where the hosted bundle lives; `public/vector/ui` by default. |
| `VECTOR_LOGIN_URL`, `VECTOR_LOGOUT_URL` | The application's pages the hosted admin redirects to and posts to; `/login` and `/logout` by default. |
| `VECTOR_TRANSLATIONS_DIR` | A directory of `{locale}.php` files translating your labels, relative to the project root; read once at boot. See {doc}`languages`. |
| `VECTOR_UI_EXTENSIONS` | Comma-separated ES-module URLs the hosted admin imports after boot. |
| `VECTOR_SHELL_HEAD` | A raw fragment added before `</head>` of the hosted page; `{nonce}` is replaced. Deployment configuration only. |
| `VECTOR_SHELL_CSP` | A Content Security Policy for the hosted page alone, sent instead of `SECURITY_CSP`; `{nonce}` is replaced per response. Unset, the application's policy applies, and the shell uses the nonce it put on the request. See {doc}`deployment`. |

## UI settings

In a Nuxt application extending `@kinetis/vector-ui`:

| Where | Key | Meaning |
|---|---|---|
| `runtimeConfig.public.vector` | `apiBase` | The API origin in standalone mode (`NUXT_PUBLIC_VECTOR_API_BASE`). |
| | `extensions` | ES-module URLs to load at startup, in standalone mode. |
| `app.config.vector` | `brand.title` | The title before the **Branding** settings load. |
| | `basePath` | A router prefix inside the application. |
| | `commandPalette` | The <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>K</kbd> search; on by default. |
| | `idleMinutes` | Minutes without use after which the admin signs the user out; 30 by default, 0 never. See {doc}`authentication`. |
| | `usersResource` | The resource whose records are users, for **Impersonate**. |
| | `fields`, `inputs`, `widgets` | Component overrides; see {doc}`customizing-the-ui`. |
| | `locale`, `messages` | The language and message overrides; see {doc}`languages`. |
| | `pages` | Custom pages, by name. |

## See also

- {doc}`deployment` — the keys each mode needs.
