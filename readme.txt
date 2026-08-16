=== Form Image Upload Optimizer ===
Contributors: ardabasoglu
Tags: image compression, file uploads, forms, contact form 7, heic
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress form image uploads and convert HEIC/HEIF attachments to JPG before email delivery.

== Description ==

Form Image Upload Optimizer helps WordPress sites handle large image uploads submitted through forms.

Visitors often upload full-size phone photos. These files can make notification emails heavy, fill mailboxes, slow down manual review, and consume unnecessary storage. This plugin optimizes uploaded images server-side during WordPress upload handling and before Contact Form 7 sends email attachments.

= Features =

* Compress JPEG, PNG, and WebP uploads.
* Convert HEIC/HEIF files to JPG when the server supports it.
* Resize large images to configurable maximum dimensions.
* Optimize Contact Form 7 temporary uploads before mail delivery.
* Optimize image uploads from Drag and Drop Multiple File Upload for Contact Form 7 before mail delivery.
* Replace converted HEIC/HEIF Contact Form 7 attachments with JPG files.
* Skip small files below a configurable threshold.
* Keep the original when optimization would make a file larger.
* Settings page under Settings > Form Image Upload Optimizer.

= Example use cases =

* Quote request forms with photo uploads.
* Support forms with screenshots or product photos.
* Application, registration, and booking forms.
* Inspection, reporting, or documentation forms.
* Any Contact Form 7 form where users upload images.

= HEIC / HEIF support =

HEIC/HEIF conversion requires the PHP Imagick extension and an ImageMagick build with HEIC or HEIF decoding support. If this support is unavailable, the plugin leaves HEIC/HEIF files unchanged and shows a warning on the settings page. JPEG, PNG, and WebP optimization can still work through standard WordPress image editing support.

== Installation ==

1. Upload the `form-image-upload-optimizer` folder to `/wp-content/plugins/`, or install the ZIP from Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Settings > Form Image Upload Optimizer.
4. Submit a test form with image attachments.
5. Confirm the received email attachments are smaller and visually acceptable.

== Frequently Asked Questions ==

= Does this work with Contact Form 7? =

Yes. The plugin includes explicit Contact Form 7 hooks to process temporary upload files before mail is sent.

= Does this work with HEIC photos from iPhones? =

Yes, if the server has PHP Imagick and ImageMagick with HEIC/HEIF decoding support. The settings page warns you if that support is unavailable.

= Does this convert every image to JPG? =

No. JPEG, PNG, and WebP files stay in their original format and are compressed/resized. HEIC and HEIF files are converted to JPG.

= Does this change already uploaded files? =

No. It only processes new uploads after activation.

= Does this optimize images in the visitor's browser before upload? =

No. Optimization happens server-side during WordPress or Contact Form 7 upload handling.

= Will this work with every form plugin? =

It works with standard WordPress upload handling and has specific support for Contact Form 7. Other form plugins may work if they use the standard WordPress upload pipeline. Plugin-specific integrations can be added later.

== Changelog ==

= 1.1.3 =
* Add compatibility for Drag and Drop Multiple File Upload for Contact Form 7 by optimizing posted upload URLs before that plugin builds mail attachments.

= 1.1.2 =
* Remove non-standard root markdown file from production plugin package.

= 1.1.1 =
* Address Plugin Check findings for production logging, filesystem deletion/move/chmod alternatives, and readme tag count.

= 1.1.0 =
* Initial community release.
* Optimize JPEG, PNG, and WebP uploads.
* Convert HEIC/HEIF to compressed JPG for Contact Form 7 mail attachments when supported by the server.
* Add settings page and server capability warning for HEIC/HEIF conversion.

== Upgrade Notice ==

= 1.1.0 =
Initial community release.
