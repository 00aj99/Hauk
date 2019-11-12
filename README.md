![Hauk](./frontend/assets/logo.svg "Hauk")

# Hauk

[![GitHub license](https://img.shields.io/github/license/bilde2910/Hauk)](https://github.com/bilde2910/Hauk/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/bilde2910/Hauk)](https://github.com/bilde2910/Hauk/issues)
[![Translation status](https://traduki.varden.info/widgets/hauk/-/svg-badge.svg)](https://traduki.varden.info/engage/hauk/)
[![GitHub stars](https://img.shields.io/github/stars/bilde2910/Hauk)](https://github.com/bilde2910/Hauk/stargazers)
[![F-Droid](https://img.shields.io/f-droid/v/info.varden.hauk)](https://f-droid.org/packages/info.varden.hauk/)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/bilde2910/Hauk)](https://github.com/bilde2910/Hauk/releases)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/bilde2910/Hauk)
[![Docker hub](https://img.shields.io/docker/pulls/bilde2910/hauk.svg)](https://hub.docker.com/r/bilde2910/hauk)

[<img src="https://fdroid.gitlab.io/artwork/badge/get-it-on.png"
    alt="Get it on F-Droid"
    height="80">](https://f-droid.org/packages/info.varden.hauk)
[<img src="https://play.google.com/intl/en_us/badges/static/images/badges/en_badge_web_generic.png"
    alt="Get it on Google Play"
    height="80">](https://play.google.com/store/apps/details?id=info.varden.hauk)

Hauk is a fully open source, self-hosted location sharing service. Install the
backend code on a PHP-compatible web server, install the companion app on your
phone, and you're good to go!

## System Requirements

- Web server running LEMP or LAMP
- PHP Memcached or Memcache extension installed on websever.
- Android 6 or above to run the [companion Android app](https://f-droid.org/packages/info.varden.hauk/).

## Installation instructions

1. Clone or download this repository:  `git clone https://github.com/bilde2910/Hauk.git`
2. Run `sudo ./install.sh -c web_root` where `web_root` is the folder you want
   to install Hauk in, for example `/var/www/html`. Follow the instructions
   given by the install script. Make sure to set a secure hashed password and
   edit your site's domain in the configuration file after installation.
3. Start the webserver and make sure Memcached is running.
4. Install the [companion Android app](https://f-droid.org/packages/info.varden.hauk/)
   on your phone and enter your server's settings.

When you visit the webroot you may see an experation notice. Hauk uses randomly
generated URL which will be provided by the app.

## Manual installation

If you prefer not to use the install script, you can instead choose to copy the
files manually.

1. Clone or download this repository: `git clone https://github.com/bilde2910/Hauk.git`
2. Copy all files in the `backend-php` and `frontend` folders to a common folder
   in your web root, for example `/var/www/html`.
3. Modify `include/config.php` to your liking. Make sure to set a secure hashed
   password and edit your site's domain in this file.
4. Start the webserver and make sure Memcached is running.
5. Install the [companion Android app](https://f-droid.org/packages/info.varden.hauk/)
   on your phone and enter your server's settings.

## Via Docker Compose

**docker-compose.yml**

```yaml
version: '3.4'

services:
  hauk:
    image: bilde2910/hauk
    container_name: hauk
    volumes:
      - ./config/hauk:/etc/hauk
```

Copy the [config.php](https://github.com/bilde2910/Hauk/blob/master/backend-php/include/config.php) file to the ./config/hauk directory and customize it. Leave the memcached connection details as-is; memcached is included in the Docker image.

The Docker container exposes port 80. For security reasons, you should use a reverse proxy in front of Hauk that can handle TLS termination, and only expose Hauk via HTTPS. If you expose Hauk directly on port 80, or via a reverse proxy on port 80, anyone between the clients and server can intercept and read your location data.

Here's an example config for an nginx instance running in another container. You may want to customize this, especially the TLS settings and ciphers if you want compatibility with older devices.

```nginx
server {
    listen 443 ssl;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';
    ssl_session_cache shared:SSL:10m;
    ssl_stapling on;
    ssl_stapling_verify on;

    ssl_ecdh_curve 'secp521r1:secp384r1';
    ssl_prefer_server_ciphers on;
    ssl_session_timeout 10m;
    ssl_session_tickets off;

    ssl_certificate /etc/letsencrypt/live/hauk.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hauk.example.com/privkey.pem;

    add_header Referrer-Policy same-origin always;
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Robots-Tag "noindex, nofollow" always;

    server_name hauk.example.com;

    location / {
        proxy_pass http://hauk:80;
    }
}
```

## Demo server

If you'd like to see what Hauk can do, download the app and insert connection details for the demo server:

Server: https://apps.varden.info/demo/hauk/  
Password: `demo`

Location shares on the demo server is limited to 2 minutes and is only meant for demonstration purposes. Set up your own server to use Hauk to its full extent.

## Translators

Hauk depends on volunteers to translate the project. Want to help out? Head over to the [translation portal](https://traduki.varden.info/engage/hauk/) to get started.

[![Translation status](https://traduki.varden.info/widgets/hauk/-/287x66-white.png)](https://traduki.varden.info/engage/hauk/)

**Basque** - osoitz  
**Dutch** - Jdekoning141  
**French** - thifranc  
**German** - natrius and hurradiegams  
**Norwegian Bokmål** - bilde2910  
**Norwegian Nynorsk** - bilde2910  
**Polish** - krystiancha  
**Russian** - RuralYak  
**Ukrainian** - RuralYak

### Translation status

[![Translation status](https://traduki.varden.info/widgets/hauk/-/multi-red.svg)](https://traduki.varden.info/engage/hauk/)

## Donate

Hauk is an ad-free, open source project, and I am not doing this for financial gain. Thus, my time spent making this is unpaid. I do however accept donations from anyone who appreciates my work enough that they feel inclined to compensate me, no matter the amount. Donations mean a lot to me, as they help cover costs associated with server upkeep, domains and hosting, and general cost of living, and they serve as an incentive for me to keep working on open-source projects.

If you wish to donate to me, you may check out my [donations page](https://varden.info/donate.php) on my website.
