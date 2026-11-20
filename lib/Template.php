<?php
declare(strict_types=1);

final class Template
{
    public static function file(string $name): string
    {
        $custom = Stage::root() . '/custom/' . $name;
        return is_file($custom) ? $custom : Stage::root() . '/templates/' . $name;
    }

    public static function asset(string $name): string
    {
        $custom = Stage::root() . '/custom/' . $name;
        $path = is_file($custom) ? 'custom/' . $name : 'assets/' . $name;
        return $path . '?v=' . (int)filemtime(Stage::root() . '/' . $path);
    }

    public static function render(string $name, array $data): string
    {
        return self::apply((string)file_get_contents(self::file($name . '.html')), $data);
    }

    public static function apply(string $source, array $data): string
    {
        $source = (string)preg_replace_callback('/<!-- include:([a-z-]+) -->/',
            fn(array $match) => (string)file_get_contents(self::file($match[1] . '.html')), $source);
        $source = (string)preg_replace_callback('/<!-- each:(\w+) -->(.*?)<!-- end:\1 -->/s', function (array $match) use ($data) {
            $items = $data[$match[1]] ?? [];
            if (!is_array($items)) {return '';}
            return implode('', array_map(fn(array $item) => self::apply($match[2], array_merge($data, $item)), $items));
        }, $source);
        $source = (string)preg_replace_callback('/<!-- if:(!?)(\w+) -->(.*?)<!-- end:\1\2 -->/s', function (array $match) use ($data) {
            $value = $data[$match[2]] ?? false;
            $truthy = !empty($value);
            return ($match[1] === '!' ? !$truthy : $truthy) ? self::apply($match[3], $data) : '';
        }, $source);
        return (string)preg_replace_callback('/\{\{(\{?)(\w+)\}?\}\}/', function (array $match) use ($data) {
            $text = self::text($data[$match[2]] ?? '');
            return str_replace('{', '&#123;', $match[1] === '{' ? $text : htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }, $source);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) && !is_bool($value) ? (string)$value : '';
    }
}
