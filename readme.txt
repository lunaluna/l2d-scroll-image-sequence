=== Scroll Image Sequence ===
Contributors: lunaluna_dev
Tags: block, image sequence, scroll, canvas, animation
Requires at least: 6.7
Tested up to: 7.1.3
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plays an image sequence on a canvas in sync with page scroll while the section is pinned.

== Description ==

Scroll Image Sequence adds a block that pins its section to the viewport and
draws an image sequence (numbered frames) on a canvas as the visitor scrolls.
Visitors can scrub a video-like animation back and forth with the scroll.

* A regular block for the block editor. No other plugin (such as ACF) is required.
* Upload frames as a ZIP file in the "Image Sequences" admin screen.
* Choose a different sequence and settings for small screens (1024px wide or less by default).
* Overlay any blocks (headings, paragraphs, groups) on the frames.
* Blocks with the `l2dsis-reveal` class fade in at a point you choose.
* On small screens, blocks with the `l2dsis-after-mobile` class move below the pinned area, right under the frames.
* The section is pinned only when its blocks fit in the screen height. Otherwise, and when JavaScript is not available, it shows the first frame as a normal section.
* Visitors who prefer reduced motion see the last frame as a still image.
* Frames load when the section comes near, up to 6 at a time per page, nearest to the current position first. Only frames near the current position are kept in memory.

The documentation in Japanese is in README.md on GitHub:
https://github.com/lunaluna/l2d-scroll-image-sequence

== Installation ==

1. Download `l2d-scroll-image-sequence.{version}.zip` from the GitHub Releases page.
2. Upload it through "Plugins" > "Add New" > "Upload Plugin", or upload the `l2d-scroll-image-sequence` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the "Plugins" screen in WordPress.
4. This plugin is not listed on WordPress.org. It can be updated from the "Plugins" screen like any other plugin: WordPress checks GitHub Releases for new versions, and the per-plugin "Enable auto-updates" toggle also works.

To use it:

1. Go to "Image Sequences" > "Add New Image Sequence", enter a title, choose a ZIP file of frames in the "Frames" box, and publish.
2. Add the "Scroll Image Sequence" block to a post or page, and choose the sequence in the block settings.
3. Put the blocks you want to show over the frames inside the block.

== Frequently Asked Questions ==

= What should the ZIP file contain? =

WebP, JPEG or PNG images with the same aspect ratio. Frames are ordered by file name in natural order (frame-2 comes before frame-10). Folders in the ZIP file are flattened, so image file names must be unique. Other files are ignored.

Default limits: 1,000 files in the ZIP file, 300 frames, 10MB and 16 megapixels per frame, and 100MB and 500 megapixels in total. If a limit is exceeded, the import stops and the current frames are kept. The limits can be changed with the filters `l2dsis_max_zip_entries`, `l2dsis_max_frames`, `l2dsis_max_frame_bytes`, `l2dsis_max_frame_pixels`, `l2dsis_max_total_bytes` and `l2dsis_max_total_pixels`.

= Which sequences are shown on the site? =

Only published sequences. Draft and other unpublished sequences are shown only in previews, to users who can edit them.

= Are the frame images private? =

No. Frames are stored in `wp-content/uploads/l2d-scroll-image-sequence/` with public URLs, whatever the status of the sequence. Do not use this plugin for images that must not be public.

= How do I move sequences to another site? =

Use "Download frames as ZIP" on the sequence edit screen, upload the ZIP file to a new sequence on the other site, and choose the new sequence in the block.

= How do I style the content? =

The plugin only handles pinning, the canvas, layering and fade-in. Style the headings and other content in your theme. On small screens, blocks moved with `l2dsis-after-mobile` are inside `.l2dsis-sequence__after`. The minimum frame height on small screens can also be set with the CSS variable `--l2dsis-mobile-min-height`, and the small-screen breakpoint with the `l2dsis_breakpoint` filter.

= Can two people import frames into the same sequence at the same time? =

No. The later import is rejected with "Another import for this sequence is in progress", and the current frames are kept. The lock uses PHP's `flock()`, which may not work on network file systems such as NFS.

= What is deleted when I delete the plugin? =

Deactivating the plugin keeps all data. Deleting the plugin removes all image sequences (including drafts and trashed ones), the frame images in `wp-content/uploads/l2d-scroll-image-sequence/`, and the plugin's transients. This cannot be undone. Blocks in your posts stay in the content, and only their inner blocks are shown.

== Changelog ==

= 1.0.0 =
* Initial release.
