<?php
/**
 * Database helper that reads card definitions from bundled JSON files.
 */

if ( ! class_exists( 'TCG_Kiosk_Database' ) ) {
    class TCG_Kiosk_Database {
        /**
         * Absolute path to the database folder.
         *
         * @var string
         */
        protected $database_path;

        /**
         * Cache of parsed data.
         *
         * @var array|null
         */
        protected $cache = null;

        /**
         * TCG_Kiosk_Database constructor.
         *
         * @param string $database_path Absolute path to the database directory.
         */
        public function __construct( $database_path ) {
            $path                = trailingslashit( $database_path );
            $this->database_path = is_dir( $path ) ? $path : '';
        }

        /**
         * Load and return TCG data grouped by game.
         *
         * @return array
         */
        public function get_tcg_data() {
            if ( null !== $this->cache ) {
                return $this->cache;
            }

            $data = array(
                'cards'        => array(),
                'lastModified' => $this->get_last_modified_timestamp(),
            );

            $directories = $this->database_path ? glob( $this->database_path . '*', GLOB_ONLYDIR ) : array();

            if ( empty( $directories ) ) {
                $this->cache = $data;
                return $data;
            }

            foreach ( $directories as $directory ) {
                $type_slug = basename( $directory );
                $config    = $this->get_type_filter_config( $type_slug );
                $cards     = $this->collect_cards_from_directory( $directory, $config );

                if ( empty( $cards ) ) {
                    continue;
                }

                $data['cards'][] = array(
                    'slug'  => $type_slug,
                    'label' => $this->humanize_label( $type_slug ),
                    'typeLabel' => $config['label'],
                    'cards' => $cards,
                );
            }

            $this->cache = $data;

            return $data;
        }

        /**
         * Collect all cards for a given directory.
         *
         * @param string $directory Directory containing the card JSON files.
         *
         * @return array
         */
        protected function collect_cards_from_directory( $directory, array $config ) {
            $cards_directory = trailingslashit( $directory ) . 'cards';

            if ( ! is_dir( $cards_directory ) ) {
                return array();
            }

            $cards = array();

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $cards_directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $file ) {
                if ( 'json' !== strtolower( $file->getExtension() ) ) {
                    continue;
                }

                $json = file_get_contents( $file->getPathname() );

                if ( false === $json ) {
                    continue;
                }

                $decoded = json_decode( $json, true );

                if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                    continue;
                }

                foreach ( $decoded as $card ) {
                    if ( empty( $card['images'] ) || ! is_array( $card['images'] ) ) {
                        continue;
                    }

                    $image_url = $this->extract_image_url( $card['images'] );

                    if ( empty( $image_url ) ) {
                        continue;
                    }

                    $cards[] = array(
                        'id'         => isset( $card['id'] ) ? (string) $card['id'] : '',
                        'name'       => isset( $card['name'] ) ? (string) $card['name'] : '',
                        'set'        => $this->derive_set_name( $file->getBasename( '.json' ) ),
                        'imageUrl'   => esc_url_raw( $image_url ),
                        'typeValues' => $this->extract_type_values( $card, $config ),
                    );
                }
            }

            return $cards;
        }

        /**
         * Derive the configuration for the type filter based on the game slug.
         *
         * @param string $type_slug Slug for the game directory.
         *
         * @return array
         */
        protected function get_type_filter_config( $type_slug ) {
            $slug = strtolower( (string) $type_slug );

            if ( false !== strpos( $slug, 'pokemon' ) ) {
                return array(
                    'label' => __( 'Type', 'tcg-kiosk-filter' ),
                    'field' => 'types',
                );
            }

            if ( false !== strpos( $slug, 'one-piece' ) ) {
                return array(
                    'label' => __( 'Color', 'tcg-kiosk-filter' ),
                    'field' => 'color',
                );
            }

            if ( false !== strpos( $slug, 'gundam' ) ) {
                return array(
                    'label' => __( 'Color', 'tcg-kiosk-filter' ),
                    'field' => 'color',
                );
            }

            if ( false !== strpos( $slug, 'riftbound' ) ) {
                return array(
                    'label' => __( 'Domain', 'tcg-kiosk-filter' ),
                    'field' => 'domain',
                );
            }

            return array(
                'label' => __( 'Type', 'tcg-kiosk-filter' ),
                'field' => '',
            );
        }

        /**
         * Extract the relevant type values for a card based on the configuration.
         *
         * @param array $card   Raw card data.
         * @param array $config Type filter configuration.
         *
         * @return array
         */
        protected function extract_type_values( array $card, array $config ) {
            $values = array();

            switch ( $config['field'] ) {
                case 'types':
                    if ( ! empty( $card['types'] ) && is_array( $card['types'] ) ) {
                        $values = $card['types'];
                    }
                    break;
                case 'color':
                    if ( ! empty( $card['color'] ) ) {
                        if ( is_array( $card['color'] ) ) {
                            $values = $card['color'];
                        } else {
                            $values = array( $card['color'] );
                        }
                    }
                    break;
                case 'domain':
                    if ( ! empty( $card['domain'] ) ) {
                        $values = array( $card['domain'] );
                    }
                    break;
            }

            if ( empty( $values ) ) {
                return array();
            }

            $normalized = array();

            foreach ( $values as $value ) {
                if ( is_string( $value ) || is_numeric( $value ) ) {
                    $clean = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

                    if ( '' !== $clean ) {
                        $normalized[] = $clean;
                    }
                }
            }

            if ( empty( $normalized ) ) {
                return array();
            }

            return array_values( array_unique( $normalized ) );
        }

        /**
         * Extract the most appropriate image URL from the provided data.
         *
         * @param array $images List of images indexed by size.
         *
         * @return string
         */
        protected function extract_image_url( array $images ) {
            foreach ( array( 'large', 'small', 'normal', 'image' ) as $key ) {
                if ( ! empty( $images[ $key ] ) ) {
                    return $images[ $key ];
                }
            }

            return '';
        }

        /**
         * Derive a human readable set name from the file name.
         *
         * @param string $filename File name without extension.
         *
         * @return string
         */
        protected function derive_set_name( $filename ) {
            if ( empty( $filename ) ) {
                return '';
            }

            $name = str_replace( array( '-', '_' ), ' ', $filename );
            $name = preg_replace( '/\s+/', ' ', $name );

            return ucwords( trim( $name ) );
        }

        /**
         * Convert a slug into a human readable label.
         *
         * @param string $slug Slug string.
         *
         * @return string
         */
        protected function humanize_label( $slug ) {
            if ( empty( $slug ) ) {
                return '';
            }

            $label = str_replace( array( '-', '_' ), ' ', $slug );
            $label = preg_replace( '/\s+/', ' ', $label );

            return ucwords( trim( $label ) );
        }

        /**
         * Determine the most recent modification timestamp for the database files.
         *
         * @return int
         */
        protected function get_last_modified_timestamp() {
            if ( empty( $this->database_path ) ) {
                return 0;
            }

            $latest = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->database_path,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                $mtime = $file->getMTime();

                if ( $mtime > $latest ) {
                    $latest = $mtime;
                }
            }

            return $latest;
        }
    }
}
