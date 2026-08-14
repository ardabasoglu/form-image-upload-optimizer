# Form Image Upload Optimizer

Form Image Upload Optimizer is a lightweight WordPress plugin for websites that receive image uploads through forms.

Many form submissions include large phone photos. Those files can make notification emails heavy, fill mailboxes, slow down workflows, and consume unnecessary server storage. This plugin optimizes uploaded images server-side before they are attached to form emails or stored through normal WordPress upload handling.

## Features

- Compresses JPEG, PNG, and WebP image uploads.
- Converts iPhone HEIC/HEIF files to compressed JPG when the server supports it.
- Resizes oversized images to configurable maximum dimensions.
- Works with WordPress' normal upload pipeline.
- Includes Contact Form 7 support:
  - optimizes temporary uploaded files before mail is sent
  - replaces converted HEIC/HEIF mail attachments with JPG files
- Skips small files below a configurable threshold.
- Keeps the original file if optimization would make it larger.
- Adds a settings page under **Settings → Form Image Upload Optimizer**.

## Who this is for

Use this plugin if your WordPress site has forms where visitors upload images, for example:

- quote request forms
- support forms
- application forms
- registration forms
- booking forms
- inspection or report forms
- before/after or project photo forms
- any Contact Form 7 form with file upload fields

## Installation

1. Upload the `form-image-upload-optimizer` folder to `wp-content/plugins/`, or install the ZIP from WordPress Admin → Plugins → Add New → Upload Plugin.
2. Activate **Form Image Upload Optimizer**.
3. Go to **Settings → Form Image Upload Optimizer**.
4. Submit a test form with image attachments.
5. Confirm the received email attachments are smaller and visually acceptable.

## Recommended defaults

| Setting | Value |
| --- | ---: |
| JPEG quality | `72` |
| WebP quality | `72` |
| PNG compression | `7` |
| Maximum dimensions | `1600 × 1600` |
| Only optimize files larger than | `250 KB` |
| Keep original if optimized is larger | enabled |

If files are still too large, lower JPEG/WebP quality to `65` or reduce max dimensions to `1400 × 1400`.

## HEIC / HEIF support

HEIC/HEIF conversion requires:

- PHP `Imagick` extension
- ImageMagick with `HEIC` or `HEIF` decoding support

If the server does not support this, the plugin logs a message, leaves HEIC/HEIF files unchanged, and shows a warning on its settings page. JPEG/PNG/WebP optimization still works with WordPress image editing support through GD or Imagick.

## Compatibility

Best tested path:

- WordPress upload handling
- Contact Form 7 upload fields and mail attachments

Other form plugins may work if they use the standard WordPress upload pipeline. If a form plugin stores temporary files and builds emails without WordPress upload handling, it may need a plugin-specific integration hook.

## Development

Run a syntax check:

```bash
php -l form-image-upload-optimizer/form-image-upload-optimizer.php
```

Build an installable ZIP from the parent directory:

```bash
zip -r form-image-upload-optimizer.zip form-image-upload-optimizer
```

## License

GPL-2.0-or-later.
