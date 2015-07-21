# artisan-io

This package adds some basic import capability to your Laravel 5 project.

## Installation

### Install through composer

```bash
$ composer require pmatseykanets/artisan-io
```

### Register the command

Open `app\Console\Kernel.php` in the editor of your choice and add the command to the `$commands` array

```php
protected $commands = [
    \ArtisanIo\Console\ImportDelimitedCommand::class,
];
```


## Usage


```
$ php artisan import:delimited --help

Usage:
  import:delimited [options] [--] <from> <to>

Arguments:
  from                           The path to an import file i.e. /tmp/import.csv
  to                             The table (i.e. employees) or Eloquent class name (i.e. "\App\Models\Employee")

Options:
  -f, --fields[=FIELDS]          A comma separated list of fields in the form <field>[:position] i.e. "email:0,name,2". Positions are 0 based
  -F, --field-file[=FIELD-FILE]  Path to a file that contains field definitions. One field definition per line
  -m, --mode[=MODE]              Import mode [insert|update|upsert] [default: "upsert"]
  -k, --key[=KEY]                Field names separated by a comma that constitute a key for update and upsert modes
  -R, --rule-file[=RULE-FILE]    Path to a file, containing field validation rules
  -d, --delimiter[=DELIMITER]    Field delimiter [default: ","]
  -i, --ignore[=IGNORE]          Ignore first N lines of the file
  -c, --database[=DATABASE]      The database connection to use
  -x, --transaction              Use a transaction
      --dry-run                  Dry run mode
      --no-progress              Don't show the progress bar
      --force                    Force the operation to run when in production
```


### License

The artisan-io is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
