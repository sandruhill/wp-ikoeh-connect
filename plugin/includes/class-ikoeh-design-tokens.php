<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts structured design tokens from a design document's markdown.
 * Targets the documented front-matter shape (single-level maps for
 * colors/spacing/rounded/components/dials, two-level for typography,
 * 2-space indentation per level) -- not a general YAML parser. Falls back
 * to simple prose heuristics (hex codes, a "fonte:"/"font:" mention) when
 * front-matter has no colors/typography, since real-world pasted brand
 * descriptions won't always follow the structured format.
 */
class Ikoeh_Connect_Design_Tokens {

    private static function front_matter_lines($raw) {
        if (0 !== strpos(ltrim($raw), '---')) {
            return [];
        }
        $trimmed = ltrim($raw);
        $end = strpos($trimmed, '---', 3);
        if (false === $end) {
            return [];
        }
        $block = substr($trimmed, 3, $end - 3);
        return explode("\n", $block);
    }

    private static function unquote($value) {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    public static function parse_name_description($content) {
        $name = '';
        $description = '';
        foreach (self::front_matter_lines($content) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || 0 === strpos($trimmed, '#')) {
                continue;
            }
            $colon = strpos($trimmed, ':');
            if (false === $colon) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            if (0 !== $indent) {
                continue;
            }
            $key = self::unquote(substr($trimmed, 0, $colon));
            $value = self::unquote(substr($trimmed, $colon + 1));
            if ('name' === $key) {
                $name = $value;
            }
            if ('description' === $key) {
                $description = $value;
            }
        }
        return ['name' => $name, 'description' => $description];
    }

    public static function extract($content) {
        $colors = [];
        $typography = [];
        $spacing = [];
        $rounded = [];
        $components = [];
        $dials = [];

        $section = null;
        $token = null;

        foreach (self::front_matter_lines($content) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || 0 === strpos($trimmed, '#')) {
                continue;
            }
            $colon = strpos($trimmed, ':');
            if (false === $colon) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            $key = self::unquote(substr($trimmed, 0, $colon));
            $value = self::unquote(substr($trimmed, $colon + 1));

            if (0 === $indent) {
                if ('' !== $value && 'rounded' === $key) {
                    $rounded['md'] = $value;
                    $section = null;
                    $token = null;
                    continue;
                }
                if ('' !== $value && 'spacing' === $key) {
                    $spacing['md'] = $value;
                    $section = null;
                    $token = null;
                    continue;
                }
                $section = ('' === $value && in_array($key, ['colors', 'typography', 'spacing', 'rounded', 'components', 'dials'], true)) ? $key : null;
                $token = null;
                continue;
            }

            if ('colors' === $section && '' !== $value) {
                $colors[$key] = $value;
                continue;
            }
            if ('spacing' === $section && '' !== $value) {
                $spacing[$key] = $value;
                continue;
            }
            if ('rounded' === $section && '' !== $value) {
                $rounded[$key] = $value;
                continue;
            }
            if ('components' === $section && '' !== $value) {
                $components[$key] = $value;
                continue;
            }
            if ('dials' === $section && '' !== $value) {
                $dials[$key] = $value;
                continue;
            }
            if ('typography' !== $section) {
                continue;
            }
            if ($indent <= 2 && '' === $value) {
                $token = $key;
                $typography[$key] = [];
                continue;
            }
            if ($indent <= 2) {
                $token = null;
                $matches = [];
                if (1 === preg_match('/^(.*\S)\s+([1-9]00)$/', $value, $matches)) {
                    $typography[$key] = ['fontFamily' => $matches[1], 'fontWeight' => $matches[2]];
                    continue;
                }
                $typography[$key] = ['fontFamily' => $value];
                continue;
            }
            if ($indent >= 4 && null !== $token && '' !== $value) {
                $typography[$token][$key] = $value;
            }
        }

        if (empty($colors)) {
            $colors = self::prose_colors($content);
        }
        if (empty($typography)) {
            $typography = self::prose_typography($content);
        }

        return [
            'colors' => $colors,
            'typography' => $typography,
            'spacing' => $spacing,
            'rounded' => $rounded,
            'components' => $components,
            'dials' => $dials,
        ];
    }

    private static function prose_colors($content) {
        $matches = [];
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $content, $matches);
        $colors = [];
        foreach (array_values(array_unique($matches[0])) as $index => $hex) {
            $colors['color' . ($index + 1)] = $hex;
        }
        return $colors;
    }

    private static function prose_typography($content) {
        $matches = [];
        if (1 === preg_match('/\b(?:font(?:e)?|tipografia)[:\s]+([A-Z][A-Za-z0-9 ]{2,30})/u', $content, $matches)) {
            return ['body' => ['fontFamily' => trim($matches[1])]];
        }
        return [];
    }

    public static function readiness(array $tokens) {
        $errors = [];
        $warnings = [];

        if (empty($tokens['colors'])) {
            $errors[] = 'No colors defined.';
        }
        if (empty($tokens['typography'])) {
            $errors[] = 'No typography defined.';
        }
        if (empty($tokens['spacing'])) {
            $warnings[] = 'No spacing tokens defined.';
        }
        if (empty($tokens['rounded'])) {
            $warnings[] = 'No rounded-corner tokens defined.';
        }
        if (empty($tokens['components'])) {
            $warnings[] = 'No component guidance defined.';
        }
        if (empty($tokens['dials'])) {
            $warnings[] = 'No style dials (variance/density/motion) defined.';
        }

        return ['ready' => empty($errors), 'errors' => $errors, 'warnings' => $warnings];
    }
}
