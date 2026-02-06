=== Outrank ===
Contributors: eugenezolo  
Tags: seo, content automation, article sync, ai blog  
Requires at least: 6.4  
Tested up to: 6.8  
Requires PHP: 8.0  
Stable tag: 1.0.5  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Outrank automatically creates and publishes SEO-optimized articles to your WordPress site as blog posts or drafts.

== Description ==

Grow Organic Traffic Without Lifting a Finger.

Outrank is your behind-the-scenes content team powered by AI. It creates high-quality, SEO-optimized blog posts that drive traffic to your WordPress site – automatically. No brainstorming, no writing, no scheduling. Just pure growth on autopilot.

Outrank plugin may embed external links or credits on the public site.

The plugin provides secure API access to retrieve your published posts for content analysis and optimization within the Outrank app.

== Features ==

1. Fully automatic content creation and keyword research – find hidden keyword gems and publish optimized articles daily.
2. Write in 150+ languages – speak to your audience wherever they are.
3. One-click integration with WordPress – set it up once and your content gets published like magic.
4. SEO-friendly, fact-checked articles with media – includes internal links, videos, images, and credible citations.
5. Your voice, your tone – match your brand’s style with AI-tuned tone control.
6. Up to 4000 words per article – long-form, evergreen content designed to rank and convert.
7. Smart daily publishing plan – a tailored 30-day strategy to keep content flowing.
8. Multi-user and multi-site support – manage teams and scale across sites easily.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress admin.
2. Activate the plugin from the “Plugins” page.
3. Navigate to **Outrank → Manage** in your admin menu.
4. Enter your API key and select publish mode.
5. Articles will be synced automatically via the Outrank API.

== Screenshots ==

1. Grow Organic Traffic on Autopilot.
2. The Sync Dashboard and Setup Page.
3. AI-generated blog posts ready to publish.

== External services ==

This plugin connects to the Outrank API to fetch blog article content for your site. This is necessary to sync AI-generated content to your WordPress posts.

Data sent:
- API Key (stored by user in plugin settings)

Data is sent when:
- Articles are synced via the Outrank API.

External Service:
- [Outrank API](https://www.outrank.so)
- [Privacy Policy](https://www.outrank.so/privacy-policy)
- [Terms of Use](https://www.outrank.so/terms-of-use)

== Frequently Asked Questions ==

= Does this plugin automatically post articles? =  
Yes. You can choose whether articles are saved as drafts or published instantly.

= How does the sync work? =
Outrank syncs articles to your site via a secure API connection.

== Changelog ==

= 1.0.5 =
* Added full WordPress Multisite support
* Network activation now properly creates tables for all sites
* New sites in a multisite network automatically get plugin tables created
* Fixed cache key collisions in multisite environments with shared object cache
* Proper cleanup when sites are deleted from a multisite network
* Backwards compatibility with WordPress versions before 5.1
* Removed cron-based daily sync and manual fetch triggers in favor of API-driven sync
* Added paginated dashboard with 10 articles per page

= 1.0.4 =
* Improved image downloading reliability across different server configurations
* Fixed compatibility issues with strict database settings
* Better error messages when troubleshooting sync issues
* Improved handling of image filenames and file types

= 1.0.3 =
* Add custom duplicate slugs handling

= 1.0.2 =
* Fixed YouTube video embedding in synced articles

= 1.0.1 =
* Added posts fetching endpoint for retrieving published blog posts
* Added API access functionality for content analysis and optimization
* Various bug fixes and improvements

= 1.0.0 =
* Initial release.
* Admin dashboard with API key and post settings.
* Manual and automatic article syncing via cron.

== Upgrade Notice ==

= 1.0.0 =
First release — includes cron sync, manual sync, and support for draft/publish modes.