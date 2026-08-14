# Publishing Form Image Upload Optimizer

## GitHub

Repository:

https://github.com/ardabasoglu/form-image-upload-optimizer

Release:

https://github.com/ardabasoglu/form-image-upload-optimizer/releases/tag/v1.1.0

Installable ZIP asset:

`form-image-upload-optimizer.zip`

## WordPress.org plugin directory

WordPress.org publication is a review flow. The first public GitHub release is complete, but WordPress.org requires the plugin owner to submit the plugin while logged into a WordPress.org account.

Submission page:

https://wordpress.org/plugins/developers/add/

Recommended submission fields:

- Plugin name: `Form Image Upload Optimizer`
- Plugin slug: `form-image-upload-optimizer`
- Short description: `Compress form image uploads and convert HEIC/HEIF attachments to JPG before email delivery.`
- Plugin ZIP: upload `form-image-upload-optimizer.zip` from this project root, or download it from the GitHub release.
- Public source repository: `https://github.com/ardabasoglu/form-image-upload-optimizer`

Suggested long summary:

> Form Image Upload Optimizer helps WordPress sites handle large image uploads submitted through forms. It compresses JPEG, PNG, and WebP files, resizes oversized images, and converts HEIC/HEIF files to JPG when server support is available. It includes Contact Form 7 handling so temporary file uploads can be optimized before email delivery.

After WordPress.org approval:

1. WordPress.org will provide an SVN repository URL, usually similar to:

   ```text
   https://plugins.svn.wordpress.org/form-image-upload-optimizer/
   ```

2. Check out the SVN repository:

   ```bash
   svn checkout https://plugins.svn.wordpress.org/form-image-upload-optimizer/ wordpress-org-form-image-upload-optimizer
   ```

3. Copy plugin files into `trunk/`.
4. Copy marketing assets into `assets/` if screenshots/banner/icon are created later.
5. Commit with the WordPress.org username/password:

   ```bash
   svn add trunk/* assets/* --force
   svn commit -m "Initial import of Form Image Upload Optimizer 1.1.0"
   ```

6. Tag the release:

   ```bash
   svn copy trunk tags/1.1.0
   svn commit -m "Tag version 1.1.0"
   ```

## Future improvement ideas before/after WordPress.org approval

- Add integrations for other form plugins: WPForms, Gravity Forms, Ninja Forms, Fluent Forms.
- Add optional browser-side compression to reduce upload bandwidth.
- Add settings for converting PNG/WebP to JPEG when transparency is not needed.
- Add a status/debug panel that lists server image capabilities.
- Add screenshots and WordPress.org banner/icon assets.
