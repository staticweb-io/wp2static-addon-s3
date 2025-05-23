<?php

namespace WP2StaticS3;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Aws\CloudFront\CloudFrontClient;
use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use WP2Static\WsLog;

class Deployer {

    const DEFAULT_NAMESPACE = 'wp2static-addon-s3/default';

    /**
     * @var integer
     */
    private $cf_max_paths = 0;

    /**
     * @var array
     */
    private $cf_stale_paths = [];

    /**
     * @var integer
     */
    private $deployed_ct = 0;

    /**
     * @var integer
     */
    private $deploy_cache_ct = 0;

    /**
     * @var integer
     */
    private $deploy_error_ct = 0;

    /**
     * @var string
     */
    private $namespace = self::DEFAULT_NAMESPACE;

    /**
     * @var S3Client
     */
    private $s3_client;

    public function __construct() {
        $cf_max_paths_str = Controller::getValue( 'cfMaxPathsToInvalidate' );
        if ( $cf_max_paths_str ) {
            $this->cf_max_paths = intval( $cf_max_paths_str );
        }

        $this->s3_client = self::s3Client();
    }

    public function uploadFiles( string $processed_site_path ) : void {
        // check if dir exists
        if ( ! is_dir( $processed_site_path ) ) {
            return;
        }

        // iterate each file in ProcessedSite
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $processed_site_path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $file_arrays = function ( $files, $redirects )
            use ( $processed_site_path )
            {
            foreach ( $files as $filename => $file_object ) {
                $base_name = basename( $filename );
                if ( $base_name != '.' && $base_name != '..' ) {
                    yield [
                        'filename' => $filename,
                        'path' => str_replace( $processed_site_path, '', $filename ),
                    ];
                }
            }

            foreach ( $redirects as $redirect ) {
                $path = $redirect['url'];
    
                if ( mb_substr( $path, -1 ) === '/' ) {
                    $path = $path . 'index.html';
                }

                yield [
                    'path' => $path,
                    'redirect_to' => $redirect['redirect_to'],
                ];
            }
        };

        $redirects = apply_filters( 'wp2static_list_redirects', []);

        self::uploadFilesIter( $file_arrays( $files, $redirects ) );
    }

    public function uploadFilesIter( \Iterator $files ) : void {
        $object_acl = Controller::getValue( 's3ObjectACL' );
        $base_put_data = [
            'Bucket' => Controller::getValue( 's3Bucket' ),
            'ACL'    => $object_acl === '' ? 'public-read' : $object_acl,
        ];

        $cache_control = Controller::getValue( 's3CacheControl' );
        if ( $cache_control ) {
            $base_put_data['CacheControl'] = $cache_control;
        }

        $s3_remote_path = Controller::getValue( 's3RemotePath' );
        $s3_prefix = $s3_remote_path ? $s3_remote_path . '/' : '';

        $items_by_iterKey = [];

        $command_generator = function (
            $iterator
        ) use (
            &$items_by_iterKey,
            $base_put_data,
            $s3_prefix,
        ) {
            $iterKey = 0;
            $last_log_time = microtime( true );

            foreach ( $iterator as $file ) {
                $now = microtime( true );
                $total = $this->deployed_ct + $this->deploy_cache_ct + $this->deploy_error_ct;
                if ( $total > 0 && $now - $last_log_time >= 60 ) {
                    WsLog::l( 'Deployed ' . $file['path'] );
                    $notice = "Deploy progress: $this->deployed_ct deployed," .
                              " $this->deploy_error_ct failed," .
                              " $this->deploy_cache_ct skipped (cached).";
                    WsLog::l( $notice );
                    $last_log_time = microtime( true );
                }

                $body = $file['body'] ?? null;
                $cache_key = $file['path'];
                $content_type = $file['content_type'] ?? null;
                $filename = $file['filename'] ?? null;
                $redirect_to = $file['redirect_to'] ?? null;
                $status = $file['status'] ?? null;

                if ( ! $body && $filename ) {
                    $real_filepath = realpath( $filename );

                    if ( ! $real_filepath ) {
                        $err = 'Trying to deploy unknown file to S3: ' . $filename;
                        \WP2Static\WsLog::l( $err );
                        continue;
                    }

                    // Standardise all paths to use / (Windows support)
                    $filename = str_replace( '\\', '/', $filename );

                    if ( ! is_string( $filename ) ) {
                        continue;
                    }
                }

                if ( ! $content_type && $filename ) {
                    $content_type = MimeTypes::guessMimeType( $filename );
                    if ( 'text/' === substr( $content_type, 0, 5 ) ) {
                        $content_type = $content_type . '; charset=UTF-8';
                    }
                }

                if ( $body !== null ) {
                    $file_hash = md5( $body, true );
                } else if ( $filename ) {
                    $file_hash = md5_file( $filename, true);
                }

                $s3_key = $s3_prefix . ltrim( $cache_key, '/' );
                if ( mb_substr( $s3_key, -1 ) === '/' ) {
                    $s3_key = $s3_key . 'index.html';
                }

                if ( $status === 404 ) {
                    $cmd_name = 'DeleteObject';
                    $cmd_data = [
                        'Bucket' => $base_put_data['Bucket'],
                        'Key' => $s3_key,
                    ];
                    $hash = md5( $cmd_name . (string) json_encode( $cmd_data ) );
                } else {
                    $cmd_name = 'PutObject';
                    $cmd_data = array_merge( [], $base_put_data );

                    if ( $redirect_to ) {
                        $cmd_data['WebsiteRedirectLocation'] = $redirect_to;
                    } else if ( ! $file_hash ) {
                        WsLog::l( 'Failed to hash file ' . $filename );
                        continue;
                    } else {
                        $cmd_data['ContentMD5'] = base64_encode( $file_hash );
                        $cmd_data['ContentType'] = $content_type;
                    }
    
                    $cmd_data['Key'] = $s3_key;
                    $hash = md5( $cmd_name . (string) json_encode( $cmd_data ) );
    
                    if ( $body !== null ) {
                        $cmd_data['Body'] = $body;
                    } else if ( $filename ) {
                        $cmd_data['SourceFile'] = $filename;
                    }
    
                    if ( ! isset( $cmd_data['Body'] ) && ! $cmd_data['SourceFile'] && ! $cmd_data['WebsiteRedirectLocation'] ) {
                        WsLog::l( 'Invalid deploy data: ' . json_encode( $file ) );
                        continue;
                    }
                }

                $is_cached = \WP2Static\DeployCache::fileisCached(
                    $cache_key,
                    $this->namespace,
                    $hash,
                );

                if ( $is_cached ) {
                    $this->deploy_cache_ct++;
                    continue;
                }

                // Save data so we can retrieve it by iterKey
                // in the fulfilled handler
                $items_by_iterKey[$iterKey] = [
                    'cache_key' => $cache_key,
                    'hash' => $hash
                ];
                $iterKey++;

                yield $this->s3_client->getCommand($cmd_name, array_merge( [], $cmd_data ) );
            }
        };

        $commands = $command_generator( $files );

        $concurrency = intval ( Controller::getValue( 's3Concurrency' ) || '4' );
        $config = [
            'concurrency' => $concurrency,
        ];

        $cmd_pool = new CommandPool(
            $this->s3_client,
            $commands,
            [
                'fulfilled' => function ($result, $iterKey, $promise)
                    use ( &$items_by_iterKey ) {
                    $item = $items_by_iterKey[$iterKey];
                    \WP2Static\DeployCache::addFile( $item['cache_key'], $this->namespace, $item['hash'] );
                    $this->addCfPath( $item['cache_key'] );
                    unset($items_by_iterKey[$iterKey]);
                    $this->deployed_ct++;
                },
                'rejected' => function ( $reason, $iterKey, $promise)
                    use ( &$items_by_iterKey ) {
                    $item = $items_by_iterKey[$iterKey];
                    WsLog::l( 'Error uploading file ' . $item['cache_key'] . ': ' . $reason );
                    unset($items_by_iterKey[$iterKey]);
                    $this->deploy_error_ct++;
                }
            ],
            $config
        );

        $cmd_pool->promise()->wait();

        $total = $this->deployed_ct + $this->deploy_cache_ct + $this->deploy_error_ct;
        if ( $total % 300 === 0 ) {
            $notice = "Deploy progress: $this->deployed_ct deployed," .
                      " $this->deploy_error_ct failed," .
                      " $this->deploy_cache_ct skipped (cached).";
            WsLog::l( $notice );
        }

        $distribution_id = Controller::getValue( 'cfDistributionID' );
        $num_stale = count( $this->cf_stale_paths );
        if ( $distribution_id && $num_stale > 0 ) {
            if ( $num_stale > $this->cf_max_paths ) {
                WsLog::l( 'Invalidating all CloudFront paths' );
                self::invalidateItems( $distribution_id, [ '/*' ] );
            } else {
                $path_text = ( $num_stale === 1 ) ? 'path' : 'paths';
                WsLog::l( "Invalidating $num_stale CloudFront $path_text" );
                self::invalidateItems( $distribution_id, $this->cf_stale_paths );
            }
        }
    }

    public static function s3Client() : \Aws\S3\S3Client {
        $client_options = [
            'version' => 'latest',
            'region' => Controller::getValue( 's3Region' ),
        ];

        /*
           If no credentials option, SDK attempts to load credentials from
           your environment in the following order:

           - environment variables.
           - a credentials .ini file.
           - an IAM role.
         */
        if (
            Controller::getValue( 's3AccessKeyID' ) &&
            Controller::getValue( 's3SecretAccessKey' )
        ) {
            $client_options['credentials'] = [
                'key' => Controller::getValue( 's3AccessKeyID' ),
                'secret' => \WP2Static\CoreOptions::encrypt_decrypt(
                    'decrypt',
                    Controller::getValue( 's3SecretAccessKey' )
                ),
            ];
        } elseif ( Controller::getValue( 's3Profile' ) ) {
            $client_options['profile'] = Controller::getValue( 's3Profile' );
        }

        return new \Aws\S3\S3Client( $client_options );
    }

    public static function cloudfrontClient() : \Aws\CloudFront\CloudFrontClient {
        /*
            If no credentials option, SDK attempts to load credentials from
            your environment in the following order:
                 - environment variables.
                 - a credentials .ini file.
                 - an IAM role.
        */
        if (
            Controller::getValue( 'cfAccessKeyID' ) &&
            Controller::getValue( 'cfSecretAccessKey' )
        ) {
            // Use the supplied access keys.
            $credentials = new \Aws\Credentials\Credentials(
                Controller::getValue( 'cfAccessKeyID' ),
                \WP2Static\CoreOptions::encrypt_decrypt(
                    'decrypt',
                    Controller::getValue( 'cfSecretAccessKey' )
                )
            );
            $client = \Aws\CloudFront\CloudFrontClient::factory(
                [
                    'region' => Controller::getValue( 'cfRegion' ),
                    'version' => 'latest',
                    'credentials' => $credentials,
                ]
            );
        } elseif ( Controller::getValue( 'cfProfile' ) ) {
            // Use the specified profile.
            $client = \Aws\CloudFront\CloudFrontClient::factory(
                [
                    'profile' => Controller::getValue( 'cfProfile' ),
                    'region' => Controller::getValue( 'cfRegion' ),
                    'version' => 'latest',
                ]
            );
        } else {
            // Use the IAM role.
            $client = \Aws\CloudFront\CloudFrontClient::factory(
                [
                    'region' => Controller::getValue( 'cfRegion' ),
                    'version' => 'latest',
                ]
            );
        }

        return $client;
    }

    public function addCfPath(String $path) : void {
        if ( $this->cf_max_paths >= count( $this->cf_stale_paths ) ) {
            if ( 0 === substr_compare( $path, '/index.html', -11 ) ) {
                $path = substr( $path, 0, -10 );
            }
            $path = str_replace( ' ', '%20', $path );
            array_push( $this->cf_stale_paths, $path );
        }
    }

    /**
     * Create invalidation in CloudFront
     *
     * @param mixed[] $items mixed array
     */
    public static function createInvalidation( string $distribution_id, array $items ) : string {
        $client = self::cloudfrontClient();

        return $client->createInvalidation(
            [
                'DistributionId' => $distribution_id,
                'InvalidationBatch' => [
                    'CallerReference' => 'WP2Static S3 Add-on ' . time(),
                    'Paths' => [
                        'Items' => $items,
                        'Quantity' => count( $items ),
                    ],
                ],
            ]
        );
    }

    /**
     * Invalidate paths in CloudFront, catching and logging exceptions.
     *
     * @param mixed[] $items mixed array
     */
    public static function invalidateItems( string $distribution_id, array $items ) : ?string {
        try {
            return self::createInvalidation( $distribution_id, $items );
        } catch ( AwsException $e ) {
            WsLog::l( 'Error creating CloudFront invalidation: ' . $e->getMessage() );
            return null;
        }
    }

}
