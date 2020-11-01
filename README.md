# artisan-io

[![StyleCI](https://styleci.io/repos/39307509/shield)](https://styleci.io/repos/39307509)
![tests](https://github.com/pmatseykanets/artisan-io/workflows/tests/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/pmatseykanets/artisan-io/v/stable)](https://packagist.org/packages/pmatseykanets/artisan-io)
[![License](https://poser.pugx.org/pmatseykanets/artisan-io/license)](https://packagist.org/packages/pmatseykanets/artisan-io)

This package adds data import capability to your [Laravel](http://laravel.com/) project. It contains an artisan command `import:delimited` which allows you, as the name implies, to import delimited data (CSV, TSV, etc) into your local or remote database.

Main features:

- Supports multiple database connections (defined in [`config\database.php`](http://laravel.com/docs/8.x/database#introduction)).
- You can use either a table name or Eloquent model class to import your data. By using Eloquent model you can benefit from [mutators and accessors](http://laravel.com/docs/8.x/eloquent-mutators).
- Import modes:
  - insert
  - insert-new
  - update
  - upsert
- Row validation rules

## Installation

You can install the package via composer:

```bash
composer require pmatseykanets/artisan-io
```

If you're using Laravel < 5.5 or if you have package auto-discovery turned off you have to manually register the service provider:

```php
// config/app.php
'providers' => [
    ...
    ArtisanIo\ArtisanIoServiceProvider::class,
],
```

Alternatively you can register the command yourself

Open `app\Console\Kernel.php` in the editor of your choice and add the command to the `$commands` array

```php
protected $commands = [
    \ArtisanIo\Console\ImportDelimitedCommand::class,
];
```

## Usage

```bash
php artisan import:delimited --help

Usage:
  import:delimited [options] [--] <from> <to>

Arguments:
  from                           The path to an import file i.e. storage/import.csv
  to                             The table or Eloquent model class name

Options:
  -f, --fields[=FIELDS]          A comma separated list of field definitions in a form <field>[:position] i.e. "email:0,name,2". Positions are 0 based
  -F, --field-file[=FIELD-FILE]  Path to a file that contains field definitions. One definition per line
  -m, --mode[=MODE]              Import mode [insert|insert-new|update|upsert] [default: "upsert"]
  -k, --key[=KEY]                Field names separated by a comma that constitute a key for update, upsert and insert-new modes
  -R, --rule-file[=RULE-FILE]    Path to a file that contains field validation rules
  -d, --delimiter[=DELIMITER]    Field delimiter [default: ","]
  -i, --ignore[=IGNORE]          Ignore first N lines of the file
  -t, --take[=TAKE]              Take only M lines
  -c, --database[=DATABASE]      The database connection to use
  -x, --transaction              Use a transaction
      --dry-run                  Dry run mode
      --no-progress              Don't show the progress bar
      --force                    Force the operation to run when in production
```

## Examples

Lets say we have `employee.csv` file

```text
email,firstname,lastname,employed_on,phone
john.doe@example.com,John,Doe,07/01/2014,2223334455
jane.doe@example.com,Jane,Doe,02/15/2015,5554443322
```

table `employee` the migration for which may look like

```php
Schema::create('employees', function (Blueprint $table) {
    $table->increments('id');
    $table->string('email')->unique();
    $table->string('firstname', 60)->nullable();
    $table->string('lastname', 60)->nullable();
    $table->string('phone', 10)->nullable();
    $table->date('employed_on')->nullable();
    $table->timestamps();
});

```

and model `\App\Employee`

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'employees';

    protected $fillable = [
        'email',
        'firstname',
        'lastname',
        'phone',
        'employed_on'
    ];
}
```

#### Insert

If `employees` table is empty and you'd like to populate it

```bash
php artisan import:delimited employee.csv "\App\Employee" -f email:0,firstname:1,lastname:2,phone:4,employed_on:3 -m insert
```

Note: *The buity of using Eloquent model in this case is that timestamps `created_at` and `updated_at` will be populated by Eloquent automatically.*

#### Upsert

Now let's assume John's record is already present in the table. In order to update Jon's record and insert Jane's one you'd need to cnahge the mode and specify key field(s).

```bash
php artisan import:delimited employee.csv "\App\Employee" -f email:0,firstname:1,lastname:2,phone:4,employed_on:3 -m upsert -k email
```

#### Update

If you want to just update phone numbers for existing records

```bash
php artisan import:delimited employee.csv "\App\Employee" -f email:0,phone:4 -m update -k email
```

### Field definition file

Each field definition goes on a separate line in the format

`<fieldname>[:position]`

where `position` is an ordinal position of the field in the data file. The position is 0-based and can be omitted.

#### Example `employee.fld`

```text
email:0
firtname:1
lastname:2
phone:4
employed_on:3
```

### Row validation rules file

A row validation rule file is simply a php file that returns an array of rules. You can any of the [available Laravel validation rules](http://laravel.com/docs/6.0/validation#available-validation-rules)

#### Example `employee.rule`

```php
<?php

return [
    'email' => 'required|email',
    'firstname' => 'string|min:2|max:60',
    'lastname' => 'string|min:2|max:60',
    'phone' => 'digits:10|regex:/[2-9][0-9]{2}[2-9][0-9]{6}/'
    'employed_on' => 'date_format:m/d/Y|after:2010-07-15|before:'.date('Y-m-d', strtotime('tomorrow'));
];
```

## License

The artisan-io is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
