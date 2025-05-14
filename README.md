# Willhaben.vip Redirect Service

A production-ready redirect service based on sitemap standards that works across multiple server platforms including Apache, Nginx, and PHP environments.

## Purpose

This service redirects users from willhaben.at URLs to their corresponding willhaben.vip URLs using the following patterns:

- Seller profile URLs: `https://www.willhaben.at/iad/kaufen-und-verkaufen/verkaeuferprofil/34434899` → `https://willhaben.vip/rene.kapusta`
- Product URLs: `https://www.willhaben.at/iad/kaufen-und-verkaufen/d/gross-groesser-am-groessten-1998346331` → `https://willhaben.vip/rene.kapusta/gross-groesser-am-groessten-1998346331`

The service implements proper 301 (permanent) redirects to maintain SEO value and includes sitemap.xml integration for search engines.

## Components

The implementation includes the following components:

1. **sitemap.xml** - XML sitemap following the sitemap protocol standard
2. **.htaccess** - Apache configuration with mod_rewrite rules
3. **index.php** - PHP fallback script for environments without mod_rewrite
4. **nginx.conf.example** - Example Nginx configuration

## System Requirements

### For Apache with .htaccess:
- Apache 2.4+
- mod_rewrite enabled
- AllowOverride All in virtual host configuration

### For Nginx:
- Nginx 1.18+ (recommended)
- SSL certificates for HTTPS
- PHP-FPM if using the PHP fallback

### For PHP fallback:
- PHP 7.4+ (PHP 8.0+ recommended)
- write permissions for the log directory

## Installation

### 1. File Structure

Ensure your directory structure is set up as follows:

```
/public/
├── .htaccess
├── index.php
├── nginx.conf.example
├── sitemap.xml
├── README.md
└── rene.kapusta/
    └── 34434899.json
```

### 2. Apache Setup

1. Copy all files to your web server document root
2. Ensure mod_rewrite is enabled:
   ```bash
   a2enmod rewrite
   systemctl restart apache2
   ```
3. Verify your virtual host config has `AllowOverride All` for the document root

### 3. Nginx Setup

1. Copy the example from nginx.conf.example to your server configuration
2. Update paths, SSL certificates, and PHP socket location
3. Reload Nginx:
   ```bash
   nginx -t
   systemctl reload nginx
   ```

### 4. PHP Fallback Setup

1. Ensure PHP is installed with required extensions
2. Make sure the directory is writable for logging:
   ```bash
   chmod 755 /path/to/public
   touch /path/to/public/redirect_log.txt
   chmod 664 /path/to/public/redirect_log.txt
   ```

## Configuration Options

### Seller ID Mapping

You can customize seller ID mapping in the PHP script by modifying the `SELLER_MAP` constant:

```php
define('SELLER_MAP', [
    '34434899' => 'rene.kapusta',
    '12345678' => 'another.seller'
]);
```

### Adding New Sellers

1. Create a directory with the seller's URL slug: `mkdir /path/to/public/new.seller`
2. Add the JSON data file: `/path/to/public/new.seller/12345678.json`
3. Update the sitemap.xml to include the new seller's products

### Customizing Redirects

- Apache: Modify the .htaccess rewrite rules
- Nginx: Update the location blocks in the nginx configuration
- PHP: Adjust the pattern matching in index.php

## Sitemap.xml Structure

The sitemap follows the [Sitemaps XML format](https://www.sitemaps.org/protocol.html) and includes:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://www.willhaben.at/iad/kaufen-und-verkaufen/verkaeuferprofil/34434899</loc>
    <lastmod>2025-05-13T16:56:03+02:00</lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
  </url>
  <!-- Product URLs follow -->
</urlset>
```

Key elements:
- `<loc>` - Original URL that will be redirected
- `<lastmod>` - Last modification timestamp (W3C format)
- `<changefreq>` - How frequently the page changes
- `<priority>` - Relative importance (0.0 to 1.0)

## URL Redirection Examples

### Seller Profile

Original: 
```
https://www.willhaben.at/iad/kaufen-und-verkaufen/verkaeuferprofil/34434899
```

Redirects to:
```
https://willhaben.vip/rene.kapusta/
```

### Product Page

Original: 
```
https://www.willhaben.at/iad/kaufen-und-verkaufen/d/gross-groesser-am-groessten-1998346331
```

Redirects to:
```
https://willhaben.vip/rene.kapusta/gross-groesser-am-groessten-1998346331
```

## Troubleshooting

### Apache Issues

1. **404 Not Found**: Make sure mod_rewrite is enabled and AllowOverride is set to All
   ```bash
   a2enmod rewrite
   systemctl restart apache2
   ```

2. **500 Internal Server Error**: Check Apache error logs for details
   ```bash
   tail -f /var/log/apache2/error.log
   ```

### Nginx Issues

1. **Configuration errors**: Verify nginx configuration
   ```bash
   nginx -t
   ```

2. **Permission problems**: Check log file permissions
   ```bash
   chmod 644 /var/log/nginx/*.log
   ```

### PHP Issues

1. **Redirect not working**: Check if php-fpm is running
   ```bash
   systemctl status php-fpm
   ```

2. **Log errors**: View the redirect_log.txt file for detailed information
   ```bash
   tail -f /path/to/public/redirect_log.txt
   ```

## Performance Considerations

For high-traffic sites, consider:

1. Implementing server-side caching (Redis or Memcached)
2. Using a CDN for static content
3. Adding HTTP caching headers (already included in Nginx config)

## Maintenance

- Update the sitemap.xml whenever new products are added
- Monitor the redirect logs periodically
- Verify redirects are working with `curl -I` command
- Test with various user agents to ensure consistent behavior

## License

This project is provided as-is with no explicit license. See repository for details.

