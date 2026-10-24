<?php

namespace App\Support;

class RichText
{
    public static function clean(?string $html): ?string
    {
        if ($html === null) return null;
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote><table><thead><tbody><tr><th><td>');
        return preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $html);
    }
}
