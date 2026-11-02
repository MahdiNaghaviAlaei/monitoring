<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** In-memory API row for Filament 3 tables. Never persisted to a database. */
class MonitorRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = '_monitor_key';

    public static function fromApi(array $attributes, string $key): static
    {
        $record = new static;
        // Filament uses record keys in action expressions. Keep arbitrary API text
        // in attributes, but restrict the key to safe characters.
        $safeKey = preg_match('/^[a-zA-Z0-9:_-]+$/D', $key) ? $key : sha1($key);
        $record->setRawAttributes([...$attributes, '_monitor_key' => $safeKey], true);

        return $record;
    }

    public function save(array $options = [])
    {
        throw new LogicException('Monitoring records are read-only API data.');
    }

    public function delete()
    {
        throw new LogicException('Monitoring records are read-only API data.');
    }
}
