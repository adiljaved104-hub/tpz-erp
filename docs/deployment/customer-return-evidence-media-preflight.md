# Customer Return Evidence media preflight (Staging only)

This is a proposed guarded configuration change, not a deployment instruction or authorization to edit the VPS. Back up the existing service configuration and verify the target is `/var/www/tpz-erp-staging` before any separately approved change. Do not touch Main/UAT.

Read-only findings on 25 October 2026: the Staging PHP 8.3 FPM pool advertises `upload_max_filesize=10M` and `post_max_size=12M`; Staging Nginx has `client_max_body_size 25M`. The application validates videos up to 100 MiB and 120 seconds. A 16.5 MB multipart POST is rejected by PHP's POST-size guard before Laravel's evidence validation. The PHP CLI limits are not the HTTP limits.

For a 100 MiB application video limit, the proposed Staging-only settings are:

| Layer | Proposed limit |
| --- | --- |
| PHP-FPM `upload_max_filesize` | `100M` |
| PHP-FPM `post_max_size` | `110M` |
| Nginx `client_max_body_size` | `110M` |
| Laravel `RETURN_EVIDENCE_MAX_VIDEO_KIB` | existing `102400` |

The 10 MiB POST margin accommodates multipart fields. Verify any upstream proxy body cap before release. Verify effective PHP-FPM settings through an authorized non-secret diagnostic, validate Nginx syntax, reload only the approved services, and test a 16.5 MB upload with a local/synthetic fixture against a disposable environment—not an operational Staging return. If a guard fails, restore only the service configuration from the pre-change copy; never roll back evidence records automatically.

Burned-in video copies additionally require a separately reviewed FFmpeg installation with `drawtext` and `libx264`, an asynchronous Laravel queue worker, and an approved `RETURN_EVIDENCE_FFMPEG_BINARY` application setting. The current VPS has no FFmpeg/FFprobe/ImageMagick. A `sync` queue deliberately leaves video labels pending instead of transcoding a large file during an HTTP upload. Until those checks pass, accepted original videos remain immutable and the Web gallery marks labeled claim copies pending. Do not claim an unlabeled original is marketplace-ready. Verify a labeled copy with a test video and an advancing UTC timestamp before enabling this setting. Original downloads remain separately authorized.

No current production or Staging configuration is changed by this feature branch.
