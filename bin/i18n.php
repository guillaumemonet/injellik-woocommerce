<?php
/**
 * Translation tools of the plugin (development only, not shipped).
 *
 *   php bin/i18n.php pot       # languages/injellik-recommendations.pot from the PHP, JS and block.json sources
 *   php bin/i18n.php update    # adds the new strings of the .pot to the .po files, keeps the translations
 *   php bin/i18n.php compile   # .po → .mo, .l10n.php (WordPress 6.5+) and the JSON of the editor script
 *
 * Without dependencies: WP-CLI (wp i18n make-pot / make-mo / make-php / make-json) does the same on a machine that has it.
 */

const DOMAIN = 'injellik-recommendations';
const EDITOR_SCRIPT = 'blocks/recommendations/editor.js';

$root    = dirname( __DIR__ ) . '/injellik-recommendations';
$command = realpath( $argv[0] ?? '' ) === __FILE__ ? ( $argv[1] ?? '' ) : 'library';

if ( 'library' === $command ) {
	return;
} elseif ( 'pot' === $command ) {
	file_put_contents( "$root/languages/" . DOMAIN . '.pot', catalog( extract_strings( $root ) ) );
	echo 'languages/' . DOMAIN . ".pot\n";
} elseif ( 'update' === $command ) {
	$strings = extract_strings( $root );
	foreach ( glob( "$root/languages/*.po" ) as $po ) {
		$existing = parse_po( $po );
		$known    = array();
		foreach ( $existing['entries'] as $entry ) {
			$known[ key_of( $entry ) ] = $entry['msgstr'];
		}
		file_put_contents( $po, catalog( $strings, $existing['headers'], $known ) );
		echo basename( $po ) . ': ' . count( array_intersect_key( $known, $strings ) ) . ' / ' . count( $strings ) . " translated\n";
	}
} elseif ( 'compile' === $command ) {
	foreach ( glob( "$root/languages/*.po" ) as $po ) {
		compile( $po );
	}
} else {
	fwrite( STDERR, "Usage: php bin/i18n.php pot|update|compile\n" );
	exit( 1 );
}

/**
 * Strings of the sources, by key (see key_of()).
 *
 * @return array<string, array>
 */
function extract_strings( $root ) {
	$strings = array();
	$quoted  = "'(?:[^'\\\\]|\\\\.)*'";
	$pattern = '/(?:\/\*\s*translators:\s*(?<comment>.*?)\*\/\s*(?:\?>\s*<\?php\s*)?)?\b(?<fn>_n|__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(?<s>' . $quoted . ')\s*,\s*(?:(?<p>' . $quoted . ')\s*,\s*[^,]+,\s*)?\'' . DOMAIN . '\'\s*\)/s';

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$ext = $file->getExtension();
		if ( ! in_array( $ext, array( 'php', 'js' ), true ) || false !== strpos( $file->getPathname(), 'languages' ) ) {
			continue;
		}
		$relative = relative( $root, $file->getPathname() );
		$source   = file_get_contents( $file->getPathname() );
		preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $match ) {
			$plural = isset( $match['p'] ) && '' !== $match['p'][0] ? unquote( $match['p'][0] ) : null;
			$entry  = entry( unquote( $match['s'][0] ), $plural );
			$key    = key_of( $entry );
			if ( ! isset( $strings[ $key ] ) ) {
				$strings[ $key ] = $entry;
			}
			$strings[ $key ]['refs'][] = $relative . ':' . ( substr_count( substr( $source, 0, $match['fn'][1] ), "\n" ) + 1 );
			if ( ! empty( $match['comment'][0] ) ) {
				$strings[ $key ]['comment'] = trim( $match['comment'][0] );
			}
		}
	}

	// Plugin header, translated by WordPress with the text domain of the plugin.
	$main = file_get_contents( "$root/" . DOMAIN . '.php' );
	foreach ( array( 'Plugin Name', 'Description' ) as $field ) {
		if ( preg_match( '/^\s*\*\s*' . $field . ':\s*(.+)$/m', $main, $m ) ) {
			$entry           = entry( trim( $m[1] ) );
			$key             = key_of( $entry );
			$strings[ $key ] = $strings[ $key ] ?? $entry;
			$strings[ $key ]['refs'][] = DOMAIN . '.php';
		}
	}

	// block.json: title, description and keywords, with the contexts used by WordPress.
	foreach ( glob( "$root/blocks/*/block.json" ) as $file ) {
		$block = json_decode( file_get_contents( $file ), true );
		$texts = array(
			array( 'block title', $block['title'] ),
			array( 'block description', $block['description'] ),
		);
		foreach ( $block['keywords'] ?? array() as $keyword ) {
			$texts[] = array( 'block keyword', $keyword );
		}
		foreach ( $texts as list( $context, $text ) ) {
			$entry           = entry( $text, null, $context );
			$entry['refs'][] = relative( $root, $file );
			$strings[ key_of( $entry ) ] = $entry;
		}
	}

	return $strings;
}

function entry( $msgid, $plural = null, $context = null ) {
	return array(
		'context' => $context,
		'msgid'   => $msgid,
		'plural'  => $plural,
		'msgstr'  => array(),
		'refs'    => array(),
		'comment' => null,
	);
}

/**
 * Key of a message in the .mo and .l10n.php files: [context EOT] msgid [NUL plural].
 */
function key_of( array $entry ) {
	return ( null !== $entry['context'] ? $entry['context'] . "\4" : '' ) . $entry['msgid'] . ( null !== $entry['plural'] ? "\0" . $entry['plural'] : '' );
}

function relative( $root, $path ) {
	return str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
}

function unquote( $quoted ) {
	return stripcslashes( substr( $quoted, 1, -1 ) );
}

function po_string( $text ) {
	return '"' . addcslashes( $text, "\"\\\n\t" ) . '"';
}

/**
 * .pot (no translations) or .po file.
 *
 * @param array $strings Strings by key.
 * @param array $headers Headers of the file.
 * @param array $known   Translations by key.
 */
function catalog( array $strings, array $headers = array(), array $known = array() ) {
	$headers += array(
		'Project-Id-Version'        => 'Injellik Recommendations',
		'MIME-Version'              => '1.0',
		'Content-Type'              => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'X-Domain'                  => DOMAIN,
	);
	$out = "# Copyright (C) Web-Nest\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n";
	foreach ( $headers as $name => $value ) {
		$out .= po_string( "$name: $value\n" ) . "\n";
	}
	foreach ( $strings as $key => $entry ) {
		$translation = $known[ $key ] ?? array();
		$out        .= "\n";
		if ( $entry['comment'] ) {
			$out .= '#. translators: ' . $entry['comment'] . "\n";
		}
		$out .= '#: ' . implode( ' ', $entry['refs'] ) . "\n";
		if ( null !== $entry['context'] ) {
			$out .= 'msgctxt ' . po_string( $entry['context'] ) . "\n";
		}
		$out .= 'msgid ' . po_string( $entry['msgid'] ) . "\n";
		if ( null !== $entry['plural'] ) {
			$out .= 'msgid_plural ' . po_string( $entry['plural'] ) . "\n";
			$out .= 'msgstr[0] ' . po_string( $translation[0] ?? '' ) . "\n";
			$out .= 'msgstr[1] ' . po_string( $translation[1] ?? '' ) . "\n";
		} else {
			$out .= 'msgstr ' . po_string( $translation[0] ?? '' ) . "\n";
		}
	}

	return $out;
}

/**
 * @return array{headers: array<string, string>, entries: array[]}
 */
function parse_po( $file ) {
	$entries = array();
	$headers = array();
	$blocks  = preg_split( "/\n\s*\n/", str_replace( "\r\n", "\n", file_get_contents( $file ) ) );
	foreach ( $blocks as $block ) {
		$entry   = entry( null );
		$current = null;
		foreach ( explode( "\n", $block ) as $line ) {
			if ( preg_match( '/^#: (.*)$/', $line, $m ) ) {
				$entry['refs'][] = $m[1];
			} elseif ( preg_match( '/^#\. translators: (.*)$/', $line, $m ) ) {
				$entry['comment'] = $m[1];
			} elseif ( preg_match( '/^(msgctxt|msgid|msgid_plural) (".*")$/', $line, $m ) ) {
				$current           = array( 'msgctxt' => 'context', 'msgid' => 'msgid', 'msgid_plural' => 'plural' )[ $m[1] ];
				$entry[ $current ] = stripcslashes( substr( $m[2], 1, -1 ) );
			} elseif ( preg_match( '/^msgstr(?:\[(\d+)\])? (".*")$/', $line, $m ) ) {
				$current                        = (int) $m[1];
				$entry['msgstr'][ (int) $m[1] ] = stripcslashes( substr( $m[2], 1, -1 ) );
			} elseif ( preg_match( '/^(".*")$/', $line, $m ) && null !== $current ) {
				$text = stripcslashes( substr( $m[1], 1, -1 ) );
				if ( is_int( $current ) ) {
					$entry['msgstr'][ $current ] .= $text;
				} else {
					$entry[ $current ] .= $text;
				}
			}
		}
		if ( null === $entry['msgid'] ) {
			continue;
		}
		if ( '' === $entry['msgid'] ) {
			foreach ( explode( "\n", $entry['msgstr'][0] ?? '' ) as $header ) {
				if ( strpos( $header, ':' ) ) {
					list( $name, $value )     = explode( ':', $header, 2 );
					$headers[ trim( $name ) ] = trim( $value );
				}
			}
			continue;
		}
		$entries[] = $entry;
	}

	return array(
		'headers' => $headers,
		'entries' => $entries,
	);
}

function compile( $po ) {
	$data   = parse_po( $po );
	$locale = preg_replace( '/^.*-([a-z]{2,3}_[A-Z]{2})\.po$/', '$1', $po );
	$base   = substr( $po, 0, -3 );
	$plural = $data['headers']['Plural-Forms'] ?? 'nplurals=2; plural=(n > 1);';

	$messages = array();
	$jed      = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $locale,
			'plural-forms' => $plural,
		),
	);
	foreach ( $data['entries'] as $entry ) {
		if ( '' === implode( '', $entry['msgstr'] ) ) {
			continue;
		}
		$messages[ key_of( $entry ) ] = implode( "\0", $entry['msgstr'] );
		if ( false !== strpos( implode( ' ', $entry['refs'] ), EDITOR_SCRIPT ) ) {
			$jed[ $entry['msgid'] ] = array_values( $entry['msgstr'] );
		}
	}

	// Gettext .mo.
	$header = '';
	foreach ( $data['headers'] as $name => $value ) {
		$header .= "$name: $value\n";
	}
	$mo = array( '' => $header ) + $messages;
	ksort( $mo, SORT_STRING );
	file_put_contents( $base . '.mo', mo( $mo ) );

	// PHP translation file of WordPress 6.5+ (loaded instead of the .mo, faster).
	$php = array(
		'domain'             => DOMAIN,
		'plural-forms'       => $plural,
		'language'           => $locale,
		'project-id-version' => 'Injellik Recommendations',
		'messages'           => $messages,
	);
	file_put_contents( $base . '.l10n.php', '<?php' . "\n" . 'return ' . var_export( $php, true ) . ';' . "\n" );

	// JSON of the editor script (Jed format), named after the md5 of its path in the plugin.
	$json = array(
		'translation-revision-date' => gmdate( 'Y-m-d H:iO' ),
		'generator'                 => 'bin/i18n.php',
		'domain'                    => 'messages',
		'locale_data'               => array( 'messages' => $jed ),
	);
	file_put_contents( $base . '-' . md5( EDITOR_SCRIPT ) . '.json', json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );

	echo basename( $po ) . ': ' . count( $messages ) . " translations\n";
}

function mo( array $messages ) {
	$count   = count( $messages );
	$ids     = '';
	$strs    = '';
	$offsets = array();
	foreach ( $messages as $id => $str ) {
		$offsets[] = array( strlen( $ids ), strlen( (string) $id ), strlen( $strs ), strlen( $str ) );
		$ids      .= $id . "\0";
		$strs     .= $str . "\0";
	}
	$ids_start  = 28 + 16 * $count;
	$strs_start = $ids_start + strlen( $ids );
	$table_ids  = '';
	$table_strs = '';
	foreach ( $offsets as $o ) {
		$table_ids  .= pack( 'VV', $o[1], $ids_start + $o[0] );
		$table_strs .= pack( 'VV', $o[3], $strs_start + $o[2] );
	}

	return pack( 'VVVVVVV', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, 0 ) . $table_ids . $table_strs . $ids . $strs;
}
