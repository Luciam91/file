# fs

[![Build Status](https://img.shields.io/travis/amphp/fs/master.svg?style=flat-square)](https://travis-ci.org/amphp/fs)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/fs/master.svg?style=flat-square)](https://coveralls.io/github/amphp/fs?branch=master)
![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)


`amphp/fs` is a non-blocking filesystem library for use with the [`amp`](https://github.com/amphp/amp)
concurrency framework.

**Dependencies**

- PHP 5.5+
- [eio](https://pecl.php.net/package/eio)
- [php-uv](https://github.com/bwoebi/php-uv) (experimental, requires PHP7)

`amphp/fs` works out of the box without any PHP extensions but it does so using
blocking functions. This capability only exists to simplify development across
environments where extensions may not be present. Using `amp/fs` in production
without pecl/eio or php-uv is **NOT** recommended.

**Current Version**

`amphp/fs` is currently pre-alpha software and has no tagged releases. Your mileage may vary.

**Installation**

```bash
$ composer require amphp/fs:dev-master
```

**TODO**

- seek/read/write to/from specific offsets on open file handles