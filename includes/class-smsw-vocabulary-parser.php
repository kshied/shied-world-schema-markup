<?php
/**
 * Parser for the bundled schema.org vocabulary.
 *
 * Reads the vocabulary JSON file shipped with the plugin, builds type and
 * property maps from the JSON-LD graph, and caches the parsed result in a
 * transient. The maps feed the schema type picker and the property fields
 * offered in the block builder.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the schema.org vocabulary into type, property, and inheritance maps.
 */
class SMSW_Vocabulary_Parser {

	/**
	 * Absolute path to the bundled vocabulary JSON file.
	 *
	 * @var string
	 */
	private $vocab_file;

	/**
	 * Every rdfs:Class in the vocabulary, keyed by its short name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $types = array();

	/**
	 * Every rdf:Property in the vocabulary, keyed by its short name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $properties = array();

	/**
	 * Class short name mapped to the list of its parent class names.
	 *
	 * @var array<string,array<int,string>>
	 */
	private $inheritance_map = array();

	/**
	 * Shared instance for the current request.
	 *
	 * Parsing the bundled vocabulary costs real time and memory, so every
	 * caller shares one instance and its per type resolution memo. This is a
	 * plain static property rather than a persistent cache: a transient holding
	 * the parsed vocabulary was itself large enough to push the database layer
	 * past the memory limit.
	 *
	 * @var SMSW_Vocabulary_Parser|null
	 */
	private static $shared = null;

	/**
	 * Returns the shared parser, building it on first use.
	 *
	 * @return SMSW_Vocabulary_Parser
	 */
	public static function instance() {
		if ( ! self::$shared instanceof self ) {
			self::$shared = new self();
		}

		return self::$shared;
	}

	/**
	 * Drops the shared instance.
	 *
	 * @return void
	 */
	public static function reset_shared_instance() {
		self::$shared = null;
	}

	/**
	 * Property short name mapped to its range and domain metadata.
	 *
	 * @var array<string,array{range:array<int,string>,domain_types:array<int,string>}>
	 */
	private $property_meta = array();

	/**
	 * Type short name mapped to its resolved flat property list.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $resolved_properties = array();

	/**
	 * Loads the vocabulary and prepares the in memory maps.
	 */
	public function __construct() {
		$this->vocab_file = SMSW_PLUGIN_DIR . '/includes/data/schemaorg-vocabulary.json';
		$this->check_version_and_clear_cache();
		$this->initialize_structures( $this->load_data() );
	}

	/**
	 * Parses the bundled vocabulary.
	 *
	 * The result is deliberately not cached in a transient. The parsed
	 * vocabulary is several megabytes once serialised, and a row that large
	 * makes wpdb allocate enough memory to exhaust the PHP memory limit while
	 * reading it back. The shared instance already means the file is parsed at
	 * most once per request, which is the case that actually mattered.
	 *
	 * @return array<string,mixed> Parsed vocabulary map.
	 */
	private function load_data() {
		return $this->parse_raw_file();
	}

	/**
	 * Builds the raw vocabulary maps from the bundled JSON file.
	 *
	 * @return array<string,mixed> Parsed vocabulary map.
	 */
	private function parse_raw_file() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents -- Bundled local file.
		$raw = file_get_contents( $this->vocab_file );

		if ( false === $raw || '' === $raw ) {
			return $this->empty_map();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['@graph'] ) || ! is_array( $decoded['@graph'] ) ) {
			return $this->empty_map();
		}

		$graph = $decoded['@graph'];
		$types = array();
		$props = array();
		$inh   = array();
		$meta  = array();

		foreach ( $graph as $entry ) {
			if ( ! isset( $entry['@id'] ) || ! isset( $entry['@type'] ) ) {
				continue;
			}

			$id        = $entry['@id'];
			$short     = $this->short( $id );
			$type_list = is_array( $entry['@type'] ) ? $entry['@type'] : array( $entry['@type'] );

			if ( in_array( 'rdfs:Class', $type_list, true ) ) {
				$parents = array();

				if ( isset( $entry['rdfs:subClassOf'] ) ) {
					$p_raw  = $entry['rdfs:subClassOf'];
					$p_list = is_array( $p_raw ) ? $p_raw : array( $p_raw );
					foreach ( $p_list as $p ) {
						$parents[] = $this->short( is_array( $p ) ? $p['@id'] : $p );
					}
				}

				// The class entry and its parent list have to be written
				// together. Writing the parents unconditionally is what
				// previously left Country with no properties at all: unece:Country
				// arrives after schema:Country and, having no rdfs:subClassOf of
				// its own, blanked the inherited AdministrativeArea parent.
				if ( $this->takes_slot( $types, $short, $id ) ) {
					$types[ $short ] = $entry;
					$inh[ $short ]   = $parents;
				}
			} elseif ( in_array( 'rdf:Property', $type_list, true ) ) {
				if ( $this->takes_slot( $props, $short, $id ) ) {
					$props[ $short ] = $entry;
					$meta[ $short ]  = $this->extract_meta( $entry );
				}
			}
		}

		$all = array();
		foreach ( array_keys( $types ) as $name ) {
			if ( $this->is_main( $types[ $name ]['@id'] ) ) {
				$all[] = $name;
			}
		}
		sort( $all );

		// Properties are resolved per type on demand rather than for all 928
		// types up front. Eagerly expanding every class with its inherited
		// properties produced a structure of roughly 55,000 entries that needed
		// over 100 MB of PHP memory and about 10 MB once serialised, which
		// exhausted the memory limit on any normally configured host.
		// get_properties_for_type() resolves a single class instead, so a
		// request only ever holds the types it actually asks for.
		return array(
			'types'          => $types,
			'properties'     => $props,
			'inheritance'    => $inh,
			'all_type_names' => $all,
			'meta'           => $meta,
		);
	}

	/**
	 * Empty map returned when the bundled file is missing or unreadable.
	 *
	 * Returning a fully shaped map keeps every consumer safe without a second
	 * copy of the same array literal in this class.
	 *
	 * @return array<string,array> Empty but correctly shaped vocabulary map.
	 */
	private function empty_map() {
		return array(
			'types'               => array(),
			'properties'          => array(),
			'inheritance'         => array(),
			'all_type_names'      => array(),
			'resolved_properties' => array(),
			'meta'                => array(),
		);
	}
	/**
	 * Resolves the flat property list for one type.
	 *
	 * Walks the inheritance map with a breadth first search so every property
	 * declared by an ancestor type is carried down, and records the declaring
	 * type and inheritance depth for each property.
	 *
	 * @param string                            $type  Short name of the type to resolve.
	 * @param array<string,array<int,string>>   $inh   Inheritance map.
	 * @param array<string,array<string,mixed>> $types Class map.
	 * @param array<string,array<string,mixed>> $props Property map.
	 * @param array<string,array<string,mixed>> $meta  Property metadata map.
	 * @return array<int,array<string,mixed>> Resolved property definitions.
	 */
	private function resolve( $type, $inh, $types, $props, $meta ) {
		$depths = array( $type => 0 );
		$q      = array( $type );

		while ( ! empty( $q ) ) {
			$curr    = array_shift( $q );
			$parents = isset( $inh[ $curr ] ) ? $inh[ $curr ] : array();

			foreach ( $parents as $p ) {
				if ( ! isset( $depths[ $p ] ) ) {
					$depths[ $p ] = $depths[ $curr ] + 1;
					$q[]          = $p;
				}
			}
		}

		$res = array();

		foreach ( $props as $p_name => $p_data ) {
			$domains = isset( $meta[ $p_name ]['domain_types'] ) && is_array( $meta[ $p_name ]['domain_types'] ) ? $meta[ $p_name ]['domain_types'] : array();

			foreach ( $domains as $d ) {
				if ( ! isset( $depths[ $d ] ) ) {
					continue;
				}

				$s = null;
				if ( isset( $p_data['schema:supersededBy'] ) ) {
					$raw = $p_data['schema:supersededBy'];
					$s   = $this->short( is_array( $raw ) ? ( isset( $raw['@id'] ) ? $raw['@id'] : $raw[0]['@id'] ) : $raw );
				}

				$res[ $p_name ] = array(
					'name'            => $p_name,
					'range'           => isset( $meta[ $p_name ]['range'] ) ? $meta[ $p_name ]['range'] : array(),
					'declaring_class' => $d,
					'depth_level'     => $depths[ $d ],
					'superseded_by'   => $s,
				);
				break;
			}
		}

		return array_values( $res );
	}

	/**
	 * Reads the range and domain of one property definition.
	 *
	 * @param array<string,mixed> $entry Single vocabulary entry.
	 * @return array{range:array<int,string>,domain_types:array<int,string>}
	 */
	private function extract_meta( $entry ) {
		$r = array();

		if ( isset( $entry['schema:rangeIncludes'] ) ) {
			$r_list = is_array( $entry['schema:rangeIncludes'] ) ? $entry['schema:rangeIncludes'] : array( $entry['schema:rangeIncludes'] );
			foreach ( $r_list as $i ) {
				$r[] = $this->short( is_array( $i ) ? $i['@id'] : $i );
			}
		}

		$d = array();

		if ( isset( $entry['schema:domainIncludes'] ) ) {
			$d_list = is_array( $entry['schema:domainIncludes'] ) ? $entry['schema:domainIncludes'] : array( $entry['schema:domainIncludes'] );
			foreach ( $d_list as $i ) {
				$d[] = $this->short( is_array( $i ) ? $i['@id'] : $i );
			}
		}

		// rdfs:comment is the official schema.org description of the property.
		// It is kept as a single short line so the builder can show it as an
		// inline hint without turning the field into a paragraph.
		$desc = '';
		if ( isset( $entry['rdfs:comment'] ) ) {
			$comment = $entry['rdfs:comment'];
			// The comment is a plain string, a list of strings, or a keyed node
			// such as array( '@value' => '...' ), so it is normalised here
			// instead of assuming a positional [0] element exists.
			if ( is_array( $comment ) ) {
				if ( isset( $comment['@value'] ) ) {
					$raw_c = $comment['@value'];
				} elseif ( isset( $comment[0] ) ) {
					$raw_c = $comment[0];
				} else {
					$raw_c = reset( $comment );
				}
			} else {
				$raw_c = $comment;
			}
			if ( is_array( $raw_c ) ) {
				$raw_c = isset( $raw_c['@value'] ) ? $raw_c['@value'] : '';
			}
			$desc = is_string( $raw_c ) ? $this->one_line( $raw_c ) : '';
		}

		return array(
			'range'        => $r,
			'domain_types' => $d,
			'description'  => $desc,
		);
	}

	/**
	 * Collapses a description into one short line for use as a field hint.
	 *
	 * schema.org comments are full sentences and often run to several hundred
	 * characters. The builder shows a single line, so the text is trimmed to
	 * whole words and a sentence ending is preferred over a hard cut.
	 *
	 * @param string $text Raw description from the vocabulary.
	 * @return string One-line hint, or an empty string when nothing useful remains.
	 */
	private function one_line( $text ) {
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$limit = 130;
		if ( self::strlen_safe( $text ) <= $limit ) {
			return $text;
		}

		$cut = self::substr_safe( $text, 0, $limit );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 40 ) {
			$cut = substr( $cut, 0, $sp );
		}
		$cut = rtrim( $cut, " \t.,;:-\xC2\xA0" );

		return $cut . "\xE2\x80\xA6";
	}

	/**
	 * Multibyte safe strlen with a plain fallback.
	 *
	 * @param string $s Subject string.
	 * @return int Character count.
	 */
	private static function strlen_safe( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/**
	 * Multibyte safe substr with a byte based fallback.
	 *
	 * @param string   $s      Subject string.
	 * @param int      $start  Start offset in characters.
	 * @param int|null $length Maximum characters, or null for the remainder.
	 * @return string
	 */
	private static function substr_safe( $s, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length ? mb_substr( $s, $start, null, 'UTF-8' ) : mb_substr( $s, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( $s, $start ) : substr( $s, $start, $length );
	}

	/**
	 * Trims a full URI or prefixed term down to its short local name.
	 *
	 * @param mixed $id Full URI, prefixed term, or any other value.
	 * @return string Short local name, or an empty string when the input is not a string.
	 */
	private function short( $id ) {
		$id           = is_string( $id ) ? $id : '';
		$last_slash   = strrpos( $id, '/' );
		$last_segment = false !== $last_slash ? substr( $id, $last_slash + 1 ) : $id;
		$colon_pos    = strpos( $last_segment, ':' );

		return false !== $colon_pos ? substr( $last_segment, $colon_pos + 1 ) : $last_segment;
	}

	/**
	 * Whether a URI belongs to a published schema.org namespace term.
	 *
	 * @param mixed $id Full URI or prefixed term to test.
	 * @return bool True when the term comes from a main schema.org namespace.
	 */
	private function is_main( $id ) {
		return ( strpos( $id, 'https://schema.org/' ) === 0 ) || ( strpos( $id, 'https://schema.org#' ) !== false ) || ( strpos( $id, 'schema:' ) === 0 );
	}

	/**
	 * Decides whether an incoming vocabulary entry may take a map slot for its
	 * short name, or has to yield to what is already there.
	 *
	 * The bundled vocabulary is a JSON-LD graph that also carries the external
	 * vocabularies schema.org references, and those reuse schema.org short
	 * names. schema.org itself records them as owl:equivalentClass aliases:
	 * unece:Country, gs1:Country and lcc-cr:Country are all declared equivalent
	 * to schema:Country, and void:Dataset and dcat:Dataset to schema:Dataset.
	 * They are the same concept reached through a different namespace, not
	 * separate types, so they must never displace the schema.org term.
	 *
	 * Without this rule a plain last-write-wins assignment silently handed
	 * Country, Dataset, Invoice, Offer and Order to their unece/void twins,
	 * because those entries happen to sit later in the graph. Country then
	 * lost its parent list too and resolved to zero properties.
	 *
	 * A main namespace entry always wins. A non-main entry may still take a
	 * slot that nothing has claimed, so the external vocabularies remain
	 * available for range and domain lookups.
	 *
	 * @param array<string,array<string,mixed>> $map  Map keyed by short name.
	 * @param string                            $short Short name of the entry.
	 * @param string                            $id    Full URI of the entry.
	 * @return bool True when the incoming entry should overwrite the slot.
	 */
	private function takes_slot( $map, $short, $id ) {
		if ( ! isset( $map[ $short ] ) ) {
			return true;
		}

		$incoming_is_main = $this->is_main( $id );
		$existing_is_main = isset( $map[ $short ]['@id'] ) && $this->is_main( $map[ $short ]['@id'] );

		return $incoming_is_main || ! $existing_is_main;
	}

	/**
	 * Fills the in memory maps from a parsed vocabulary payload.
	 *
	 * @param array<string,mixed> $data Parsed vocabulary map.
	 * @return void
	 */
	private function initialize_structures( $data ) {
		$this->types               = isset( $data['types'] ) && is_array( $data['types'] ) ? $data['types'] : array();
		$this->properties          = isset( $data['properties'] ) && is_array( $data['properties'] ) ? $data['properties'] : array();
		$this->inheritance_map     = isset( $data['inheritance'] ) && is_array( $data['inheritance'] ) ? $data['inheritance'] : array();
		$this->resolved_properties = isset( $data['resolved_properties'] ) && is_array( $data['resolved_properties'] ) ? $data['resolved_properties'] : array();
		$this->property_meta       = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
	}

	/**
	 * Every published type name in the vocabulary, sorted.
	 *
	 * @return array<int,string> Sorted list of main schema.org type names.
	 */
	public function get_all_type_names() {
		$names = array();

		foreach ( $this->types as $name => $entry ) {
			if ( ! isset( $entry['@id'] ) ) {
				continue;
			}

			if ( $this->is_main( $entry['@id'] ) ) {
				$names[] = $name;
			}
		}

		sort( $names );
		return $names;
	}

	/**
	 * Flat property list for one type, including inherited properties.
	 *
	 * @param string $type Short name of the type.
	 * @return array<int,array<string,mixed>> Property definitions.
	 */
	public function get_properties_for_type( $type ) {
		$type = (string) $type;

		if ( '' === $type ) {
			return array();
		}

		// Already resolved earlier in this request: reuse it.
		if ( isset( $this->resolved_properties[ $type ] ) ) {
			return $this->resolved_properties[ $type ];
		}

		// An unknown or non schema.org name resolves to nothing rather than
		// walking the inheritance map for a class that is not in the vocabulary.
		if ( ! isset( $this->types[ $type ] ) ) {
			$this->resolved_properties[ $type ] = array();
			return $this->resolved_properties[ $type ];
		}

		$resolved = $this->resolve(
			$type,
			$this->inheritance_map,
			$this->types,
			$this->properties,
			$this->property_meta
		);

		$this->resolved_properties[ $type ] = $resolved;

		return $resolved;
	}

	/**
	 * One line description for every property that has one in the vocabulary.
	 *
	 * Keyed by the short property name, so the builder can look a hint up with
	 * the same key it already uses for the field. Properties without an
	 * rdfs:comment are simply absent, which is different from an empty string.
	 *
	 * @return array<string,string> Property name => one line description.
	 */
	public function get_property_descriptions() {
		$out = array();

		foreach ( $this->property_meta as $name => $meta ) {
			if ( is_array( $meta ) && ! empty( $meta['description'] ) ) {
				$out[ $name ] = (string) $meta['description'];
			}
		}

		return $out;
	}

	/**
	 * Deletes the parsed vocabulary cache.
	 *
	 * Also removes the oversized property definition transients left behind by
	 * earlier versions. Each of those rows is around 16 MB once serialised, so
	 * leaving them in wp_options keeps the database bloated and is the reason
	 * wpdb could exhaust the memory limit while reading one back.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( 'smsw_vocabulary_parsed' );

		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			// delete_transient() cannot match a name pattern, so the rows are removed directly.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own cache cleanup by name pattern.
			$wpdb->query(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_smsw_all_property_definitions_%'
					OR option_name LIKE '_transient_timeout_smsw_all_property_definitions_%'
					OR option_name LIKE '_transient_smsw_vocabulary_parsed%'"
			);
		}
	}

	/**
	 * Clears the cached vocabulary when the plugin version has changed.
	 *
	 * @return void
	 */
	private function check_version_and_clear_cache() {
		$stored_version = get_option( 'smsw_vocabulary_version', '' );

		if ( SMSW_VERSION !== $stored_version ) {
			self::clear_cache();
			update_option( 'smsw_vocabulary_version', SMSW_VERSION );
		}
	}
}
