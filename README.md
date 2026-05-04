External Assets (Composer plugin)
=================================

[External Assets] is a Composer plugin that allows to download external assets
(JS, CSS, fonts, images, etc.) for any PHP project.

PHP projects often need external JavaScript or CSS libraries (jQuery plugins,
Mirador, OpenSeadragon, Leaflet, etc.). These assets are typically hosted on
CDNs or GitHub releases.

The issue is that composer `repositories` key is not inherited from
dependencies. If your package defines a custom repository for a JS library,
composer won't see it when users install your package. This plugin solves the
problem by downloading assets defined in `extra.external-assets` after your
package is installed, bypassing the repository inheritance limitation. Assets
are downloaded directly from their source URLs.

This is a lightweight solution. For more features, consider [civicrm/composer-downloads-plugin],
that has more options (variables, ignore patterns, executable flag), or any
other similar package of your choice.


Installation
------------

Add to your package `composer.json`:

```json
{
    "require": {
        "sempia/external-assets": "^1.1"
    }
}
```

When users install your package via composer, assets are downloaded
automatically.


Usage
-----

Define assets in `extra.external-assets` in your package `composer.json`:

```json
{
    "extra": {
        "external-assets": {
            "asset/vendor/mirador/": "https://github.com/ProjectMirador/mirador/releases/download/v3.3.0/mirador.zip",
            "asset/vendor/lib/jquery.autocomplete.min.js": "https://cdn.example.com/jquery.autocomplete-1.5.0.min.js"
        }
    }
}
```

The key is the destination path (relative to your package directory), the value
is the source URL.

| Destination         | URL                     | Behavior                         |
|---------------------|-------------------------|----------------------------------|
| `path/to/file.js`   | `https://.../lib.js`    | Download and rename to `file.js` |
| `path/to/dir/`      | `https://.../lib.zip`   | Extract archive into `dir/`      |
| `path/to/dir/`      | `https://.../script.js` | Copy `script.js` into `dir/`     |

Rules:

1. **File destination** (no trailing `/`): Downloads and saves with the
   specified filename.

2. **Directory + archive** (trailing `/` + `.zip`/`.tar.gz`/`.tgz`): Extracts
   the archive. If it contains a single root directory, it is stripped.

3. **Directory + file** (trailing `/` + non-archive URL): Copies the file into
   the directory, keeping its original name.

### Complete example

```json
{
    "name": "your-vendor/your-package",
    "type": "library",
    "require": {
        "sempia/external-assets": "^1.1"
    },
    "autoload": {
        "psr-4": {
            "YourPackage\\": "src/"
        }
    },
    "extra": {
        "external-assets": {
            "asset/vendor/openseadragon/": "https://github.com/openseadragon/openseadragon/releases/download/v4.1.0/openseadragon-bin-4.1.0.zip",
            "asset/vendor/leaflet/": "https://unpkg.com/leaflet@1.9.4/dist/leaflet.zip",
            "asset/vendor/js/helper.min.js": "https://cdn.example.com/helper-2.0.min.js"
        }
    }
}
```

### Cli tool for manual installations

For packages installed via `git clone`, assets are not downloaded automatically.
Use the cli tool:

```sh
# From project root directory
php vendor/bin/external-assets /path/to/package

# Force re-download
php vendor/bin/external-assets --force /path/to/package

# Multiple packages
php vendor/bin/external-assets /path/to/package1 /path/to/package2
```

| Option    | Description                                   |
|-----------|-----------------------------------------------|
| `--force` | Re-download assets even if they already exist |
| `--help`  | Show usage information                        |

### Best practices

1. Use versioned release URLs instead of `main`/`master` branch links.

2. Always use HTTPS URLs for security.

3. Organize assets under `asset/vendor/` with a consistent structure.

4. Add downloaded assets to `.gitignore`:
   ```gitignore
   /asset/vendor/
   ```
   The lock file lives in `vendor/external-assets.lock.json` and is therefore
   already covered by the usual `/vendor/` gitignore.

5. Test both installation methods: `composer require` and `git clone`.


How it works
------------

The plugin subscribes to four composer events:

- **`post-package-install`** and **`post-package-update`**: download assets when
  a package is first installed or updated.

- **`post-install-cmd`** and **`post-update-cmd`**: after every `composer install`
  or `composer update`, check for missing assets across all packages (including
  the root package) and download them. This ensures assets are restored if
  deleted, and handles the root package whose assets are not covered by
  per-package events.

For each asset, the plugin downloads the file and either saves it directly,
extracts it (for archives), or copies it into the target directory.

### Lock file (`vendor/external-assets.lock.json`)

Since version 1.1, the plugin maintains a `vendor/external-assets.lock.json`
file inside each package, mapping each destination to the URL last installed:

```json
{
    "asset/vendor/openseadragon/": "https://.../openseadragon-bin-4.1.0.zip",
    "asset/vendor/js/helper.min.js": "https://cdn.example.com/helper-2.0.min.js"
}
```

At every install/update, the plugin compares the URLs declared in
`composer.json` against the URLs stored in the lock:

- If the asset is present on disk and the URL is unchanged, it is skipped.
- If the URL changed, the previous content is removed and the asset is
  re-downloaded.
- If the asset is missing, it is downloaded.

The lock file represents the **local installed state** on a given machine,
similar to `vendor/composer/installed.json` for composer. By placing it
inside `vendor/`, the lock is naturally excluded from version control (since
`vendor/` is universally gitignored). Each machine therefore maintains its
own lock, which allows the plugin to correctly detect a stale install on a
colleague's machine when only `composer.json` changes.

Removing entries from `composer.json` cleans the lock accordingly, but the
files already on disk are not removed automatically.


Requirements
------------

| Requirement             | Version  |
|-------------------------|----------|
| PHP                     | 7.2.5+   |
| Composer                | 2.0+     |
| `unzip` or `ZipArchive` | Any      |
| `tar` or `PharData`     | Any      |


Development
-----------

### Running tests

```sh
# Unit tests only
vendor/bin/phpunit --exclude-group integration

# All tests (unit + integration)
PROJECT_PATH=/path/to/project vendor/bin/phpunit
```

The `PROJECT_PATH` environment variable should point to any valid directory (e.g.,
the project where the plugin is installed). Integration tests use this path to
verify the plugin can be loaded in a real environment.


Warning
-------

Use it at your own risk.

It's always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [plugin issues] page on GitLab.

### Assets not downloading

- Ensure your package requires `sempia/external-assets`
- Verify URLs are accessible: `curl -I https://your-url.com/file.js`
- Check that the package directory is writeable

### Archive extraction fails

The plugin uses `unzip`/`ZipArchive` for `.zip` and `tar`/`PharData` for
`.tar.gz`. Ensure at least one method is available.

### Assets outdated

Normally, changing the URL in `composer.json` is enough: the next install or
update detects the URL change against `external-assets.lock.json` and
re-downloads the asset.

For a forced re-download (for example after manual changes on disk), use the
cli tool:

```sh
php vendor/bin/external-assets --force /path/to/package
```

Alternatively, `composer reinstall <vendor/package>` wipes the package
directory (including its lock file), which triggers a complete re-download on
the next event.


License
-------

This plugin is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software's author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user's attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software's suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2025-2026 (see [Daniel-KM] on GitLab)

This plugin was originally designed for [Omeka S] modules for the [digital library Manioc]
of the [Université des Antilles] (subvention Agence bibliographique de l’enseignement supérieur [Abes]).


[External Assets]: https://gitlab.com/sempia/composer-plugin-external-assets
[Omeka S]: https://omeka.org/s
[civicrm/composer-downloads-plugin]: https://github.com/civicrm/composer-downloads-plugin
[lastcall/composer-extra-files]: https://packagist.org/packages/lastcall/composer-extra-files
[plugin issues]: https://gitlab.com/sempia/composer-plugin-external-assets/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[digital library Manioc]: https://manioc.org
[Université des Antilles]: https://www.univ-antilles.fr
[Abes]: https://abes.fr
[GitLab]: https://gitlab.com/sempia/composer-plugin-external-assets
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
