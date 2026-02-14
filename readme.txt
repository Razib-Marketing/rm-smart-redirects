=== RM Smart Redirects ===
Contributors: razibmarketing
Tags: redirect, seo, broken links, 404, url management
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An intelligent SEO-focused redirect manager with hierarchical fallback and auto-slug monitoring.

== Description ==

**RM Smart Redirects** is a powerful yet user-friendly WordPress redirect management plugin designed to preserve your SEO rankings and improve user experience by intelligently handling broken links and URL changes.

= 🚀 Key Features =

**Smart Redirect Management**
* Create 301 (permanent) and 302 (temporary) redirects
* Automatic redirect creation when you change post/page slugs
* Bulk import/export redirects via CSV
* Hierarchical fallback system - automatically redirects to parent URLs when exact matches don't exist
* Search and filter redirects easily

**404 Error Tracking**
* Comprehensive 404 error logging with hit counts
* Track when broken links were last seen
* Bulk delete or manage 404 logs
* Review queue for pending redirect suggestions

**Developer-Friendly**
* Clean, modern dashboard UI
* AJAX-powered for instant updates
* Bulk actions support
* Redirect testing tool to verify rules before going live
* Extensible architecture with action/filter hooks

**SEO-Optimized**
* Preserve link equity with 301 redirects
* Prevent broken link penalties
* Automatic slug change detection
* Support for external URL redirects

= 💎 Premium Add-On Available =

Upgrade to **RM Smart Redirects PRO** for advanced features:

* **Broken Link Scanner** - Full-site crawl to find broken links automatically
* **Regex & Wildcard Redirects** - Pattern-based redirects (e.g., `/blog/*` → `/news/`)
* **Conditional Redirects** - Location-based redirects using geo-targeting
* **Advanced Analytics** - Visual charts showing redirect performance and trends
* **Export 404 Logs** - Download complete 404 reports as CSV
* **Priority Support** - Get help from our expert team

[Upgrade to PRO →](https://razibmarketing.com/rm-smart-redirects-pro/)

= 🎯 Perfect For =

* Bloggers maintaining SEO after URL restructuring
* E-commerce sites managing product URL changes
* Agencies handling client websites
* Developers needing a reliable redirect solution
* Anyone concerned about broken links hurting SEO

= 🛠️ Use Cases =

1. **Website Migration** - Preserve SEO when moving from another platform
2. **Content Restructuring** - Safely reorganize your site structure
3. **Broken Link Management** - Track and fix 404 errors before they hurt rankings
4. **URL Cleanup** - Remove outdated URLs while maintaining link equity
5. **Multi-language Sites** - Redirect based on visitor location (PRO)

= 📊 Dashboard Features =

* **Live Statistics** - Active redirects, pending queue, total hits
* **404 Logs Tab** - See all broken links with hit counts
* **Review Queue** - Approve or reject suggested redirects
* **Tools Tab** - Import/Export, Redirect Tester
* **Settings** - Configure fallback behavior and default redirect types

= 🔧 Technical Highlights =

* **Lightweight** - Minimal performance impact
* **Secure** - Nonce verification and capability checks
* **Compatible** - Works with popular SEO plugins (Yoast, Rank Math)
* **Translation Ready** - Fully localized and ready for translation
* **Modern Code** - Built with WordPress coding standards

= 🌐 External Services (PRO Only) =

The PRO version uses the **GeoJS API** (https://www.geojs.io/) for location-based redirects:
* No API key required
* No personal data sent
* Free and unlimited
* HTTPS supported
* Privacy Policy: https://www.geojs.io/docs/privacy/

Location data is cached locally for 30 minutes to minimize API calls.

== Installation ==

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "RM Smart Redirects"
3. Click **Install Now** and then **Activate**
4. Navigate to **RM Smart Redirects** in your admin sidebar

= Manual Installation =

1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the downloaded ZIP file and click **Install Now**
4. Activate the plugin
5. Navigate to **RM Smart Redirects** in your admin sidebar

= First Steps =

1. Visit the **Dashboard** tab to see your redirect overview
2. Check the **404 Logs** tab to see any broken links
3. Create your first redirect in the **Redirects** tab
4. Use the **Tools** tab to test redirects before publishing

== Frequently Asked Questions ==

= Does this plugin slow down my website? =

No! RM Smart Redirects is highly optimized and only runs when a 404 error occurs. It uses efficient database queries and caching to minimize performance impact.

= What's the difference between 301 and 302 redirects? =

* **301 (Permanent)**: Use this when a page has permanently moved. Search engines transfer SEO value to the new URL.
* **302 (Temporary)**: Use this for temporary redirects. Search engines don't transfer SEO value.

For SEO purposes, use 301 redirects in most cases.

= Can I redirect to external URLs? =

Yes! You can redirect to any external URL (e.g., `https://example.com`). The plugin automatically detects and handles external redirects.

= Does it work with custom post types? =

Yes! The plugin works with all post types including custom post types, pages, and WooCommerce products.

= How does the hierarchical fallback work? =

If someone visits `/blog/category/post-title/` and it doesn't exist, the plugin will:
1. Check for an exact redirect
2. Try `/blog/category/` (parent)
3. Try `/blog/` (grandparent)
4. Log as 404 if no fallback found

This prevents unnecessary 404 errors while maintaining user experience.

= Can I bulk import redirects? =

Yes! Use the **Tools > Import** feature to upload a CSV file with your redirects. Format:
```
source_url,target_url,type,status
/old-page,/new-page,301,active
```

= Does it detect when I change a post slug? =

Yes! The plugin automatically creates a redirect when you change a post or page slug, ensuring no broken links.

= How do I upgrade to PRO? =

Visit [razibmarketing.com](https://razibmarketing.com/rm-smart-redirects-pro/) to purchase the PRO add-on. It installs alongside the free version and adds premium features.

= Is it compatible with multisite? =

Yes, RM Smart Redirects works on WordPress multisite installations. Each site has its own redirect rules.

= Where is my data stored? =

All redirect rules and 404 logs are stored in your WordPress database using custom tables (`rmsmart_redirects` and `rmsmart_404_logs`, prefixed with your database prefix).


== Changelog ==

= 3.1.0 - 2026-02-02 =
* **Rebrand**: Plugin renamed to RM Smart Redirects
* **Update**: Database tables renamed to `rmsmart_redirects` and `rmsmart_404_logs`
* **Security**: Enhanced query string sanitization
* **Fix**: Improved slug monitor reliability

= 3.0.6 - 2026-01-27 =
* **New**: Modern, intuitive dashboard UI
* **New**: Live statistics on dashboard
* **New**: AJAX-powered instant updates
* **New**: Redirect testing tool
* **Improved**: Enhanced 404 tracking with better logging
* **Improved**: Better search and filtering
* **Improved**: Hierarchical fallback system
* **Fixed**: Ghost page detection for draft/pending posts
* **Fixed**: External URL redirect support
* **Optimized**: Database queries for better performance

= 3.0.5 =
* Improved slug change detection
* Added bulk delete for 404 logs
* Better error handling

= 3.0.0 =
* Major UI overhaul
* Added hierarchical fallback
* Improved performance

= 2.5.0 =
* Added 404 logging
* Import/export functionality
* Better WordPress 6.0+ compatibility

= 2.0.0 =
* Complete rewrite with modern architecture
* Added auto-slug monitoring
* Improved admin interface

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 3.1.0 =
Major Rebrand Update: Plugin is now RM Smart Redirects. Includes database table updates and security enhancements.

== Privacy Policy ==

**Free Version:**
This plugin does not collect, store, or transmit any personal data outside your WordPress installation. All redirect data is stored locally in your database.

**PRO Version (Optional):**
The PRO add-on uses the GeoJS API (https://www.geojs.io/) for location-based redirects:
* Only IP addresses are sent (anonymously)
* No personal user data is transmitted
* Data is cached locally for 30 minutes
* You can review GeoJS privacy policy at https://www.geojs.io/docs/privacy/

== Support ==

**Free Support:**
* Community support via WordPress.org forums
* Documentation at [razibmarketing.com/docs](https://razibmarketing.com/docs/)

**Premium Support:**
* Priority email support with PRO version
* Expert assistance within 24 hours
* Advanced troubleshooting

== Credits ==

* Company: [Razib Marketing](https://razibmarketing.com/)
* Developed By: Sezan Ahmed
* Contribution or Idea Sharing: MD Atiar Rahman Ovi, Mohammed Razib, Md Rifat, Sezan Ahmed
* Icons and design inspired by WordPress coding standards
* External service (PRO): GeoJS (https://www.geojs.io/)

== Links ==

* [Website](https://razibmarketing.com/)
* [Documentation](https://razibmarketing.com/docs/rm-smart-redirects/)
* [Upgrade to PRO](https://razibmarketing.com/rm-smart-redirects-pro/)
* [Support](https://razibmarketing.com/support/)
