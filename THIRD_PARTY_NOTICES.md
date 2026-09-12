# Third-party notices

## qrcode-generator 1.4.4

Copyright (c) 2009 Kazuhiko Arase

Licensed under the MIT License.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

Source: https://github.com/kazuhikoarase/qrcode-generator

## DB-IP Country Lite database (2026-09 edition)

Copyright DB-IP.com.

The bundled IP-to-country database is provided by DB-IP under the Creative
Commons Attribution 4.0 International License (CC BY 4.0).

- Attribution: IP Geolocation by DB-IP
- Source: https://db-ip.com/db/lite.php
- License: https://creativecommons.org/licenses/by/4.0/
- Bundled data edition: 2026-09
- Changes: No changes were made to the database content. The official gzip
  distribution was decompressed to MMDB format for local runtime use.

The plugin may download a newer monthly Country Lite MMDB edition from the
official DB-IP download host. The active edition is shown in the plugin
settings.

## AAP Japan Prefecture database derived from DB-IP City Lite (2026-09 edition)

Copyright DB-IP.com.

The bundled `aap-japan-prefecture-2026-09.mmdb` is a modified database derived
from DB-IP City Lite and is distributed under the Creative Commons Attribution
4.0 International License (CC BY 4.0).

- Attribution: IP Geolocation by DB-IP
- Source product: DB-IP City Lite (2026-09 edition)
- Source: https://db-ip.com/db/lite.php
- License: https://creativecommons.org/licenses/by/4.0/
- Changes: records outside Japan were removed; Japanese subdivision values were
  normalized to ISO 3166-2 codes `JP-01` through `JP-47`; adjacent ranges with
  the same code were merged; all city, postal, latitude, longitude, timezone,
  and other source fields were removed; the result was encoded as a new MMDB
  containing only `region_code`.
- Bundled artifact SHA-256:
  `900D8FA7FE4EDE58DF47150899974B9788CEE0DD050F38D8566EF76C181F6ADE`

This derivative database is not an official DB-IP product. Access Analytics
Plus uses it only for an approximate prefecture breakdown of confirmed Japanese
visitors. The active data edition and attribution are shown in WordPress.

## MaxMind DB Reader for PHP 1.13.1

Copyright 2013-2024 MaxMind, Inc.

Licensed under the Apache License, Version 2.0. The complete license text is
included at `includes/vendor/maxmind-db-reader/LICENSE`.

Source: https://github.com/maxmind/MaxMind-DB-Reader-php
