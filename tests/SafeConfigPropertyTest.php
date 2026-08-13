<?php

namespace Darabonba\OpenApi\Tests;

use AlibabaCloud\Credentials\Credential;
use AlibabaCloud\Credentials\Credential\Config as CredentialConfig;
use Darabonba\OpenApi\Exceptions\ClientException;
use Darabonba\OpenApi\Models\Config;
use Darabonba\OpenApi\OpenApiClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 * @covers \Darabonba\OpenApi\OpenApiClient
 */
class SafeConfigPropertyTest extends TestCase
{
    private function prop($object, $name)
    {
        $ref = new ReflectionClass($object);
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($object);
    }

    public function testConstructWithCredentialConfigEmitsNoUndefinedProperty()
    {
        $notices = [];
        set_error_handler(function ($errno, $errstr) use (&$notices) {
            if (stripos($errstr, 'Undefined property') !== false) {
                $notices[] = $errstr;
            }
            return true;
        });

        $credConfig = new CredentialConfig([
            'type' => 'access_key',
            'accessKeyId' => 'test-ak',
            'accessKeySecret' => 'test-sk',
        ]);
        $client = new OpenApiClient($credConfig);
        restore_error_handler();

        $this->assertSame([], $notices);
        $this->assertNotNull($this->prop($client, '_credential'));
        $this->assertNull($this->prop($client, '_suffix'));
        $this->assertNull($this->prop($client, '_protocol'));
        $this->assertNull($this->prop($client, '_endpoint'));
    }

    public function testConstructWithOpenApiConfigPreservesValues()
    {
        $config = new Config([
            'accessKeyId' => 'ak-reg',
            'accessKeySecret' => 'sk-reg',
            'endpoint' => 'green.cn-shanghai.aliyuncs.com',
            'regionId' => 'cn-shanghai',
            'protocol' => 'https',
            'method' => 'POST',
            'userAgent' => 'unit-test-ua',
        ]);
        $client = new OpenApiClient($config);

        $this->assertSame('green.cn-shanghai.aliyuncs.com', $this->prop($client, '_endpoint'));
        $this->assertSame('cn-shanghai', $this->prop($client, '_regionId'));
        $this->assertSame('https', $this->prop($client, '_protocol'));
        $this->assertSame('POST', $this->prop($client, '_method'));
        $this->assertSame('unit-test-ua', $this->prop($client, '_userAgent'));
        $this->assertNull($this->prop($client, '_suffix'));
        $this->assertNull($this->prop($client, '_httpProxy'));
        $this->assertNotNull($this->prop($client, '_credential'));
    }

    public function testConstructStsAndBearerBranches()
    {
        $sts = new Config([
            'accessKeyId' => 'ak',
            'accessKeySecret' => 'sk',
            'securityToken' => 'token',
            'endpoint' => 'example.com',
        ]);
        $clientSts = new OpenApiClient($sts);
        $this->assertNotNull($this->prop($clientSts, '_credential'));

        $bearer = new Config([
            'bearerToken' => 'btoken',
            'endpoint' => 'example.com',
        ]);
        $clientBearer = new OpenApiClient($bearer);
        $this->assertNotNull($this->prop($clientBearer, '_credential'));

        $creConfig = new CredentialConfig([
            'accessKeyId' => 'ak2',
            'accessKeySecret' => 'sk2',
            'type' => 'access_key',
        ]);
        $credential = new Credential($creConfig);
        $withCred = new Config([
            'credential' => $credential,
            'endpoint' => 'example.com',
        ]);
        $clientCred = new OpenApiClient($withCred);
        $this->assertSame($credential, $this->prop($clientCred, '_credential'));
    }

    public function testCheckConfigWithMissingEndpointPropertyDoesNotNotice()
    {
        $notices = [];
        set_error_handler(function ($errno, $errstr) use (&$notices) {
            if (stripos($errstr, 'Undefined property') !== false) {
                $notices[] = $errstr;
            }
            return true;
        });

        $config = new Config([
            'accessKeyId' => 'ak',
            'accessKeySecret' => 'sk',
            'endpoint' => 'ok.example.com',
        ]);
        $client = new OpenApiClient($config);
        $client->checkConfig($config);

        $credOnly = new CredentialConfig([
            'type' => 'access_key',
            'accessKeyId' => 'ak',
            'accessKeySecret' => 'sk',
        ]);
        $client2 = new OpenApiClient($credOnly);
        $client2->checkConfig($credOnly);
        restore_error_handler();

        $this->assertSame([], $notices);
    }

    public function testCheckConfigThrowsWhenEndpointNullOnOpenApiConfig()
    {
        $config = new Config([
            'accessKeyId' => 'ak',
            'accessKeySecret' => 'sk',
        ]);
        $config->endpoint = null;
        $client = new OpenApiClient($config);
        $thrown = false;
        try {
            $client->checkConfig($config);
        } catch (\Exception $e) {
            // PHP 5.6 has no Throwable. ClientException historically requires
            // statusCode; either ClientException or the ctor Error proves we
            // entered the ParameterMissing throw branch.
            $thrown = true;
            $this->assertTrue(
                ($e instanceof ClientException)
                || (stripos($e->getMessage(), 'statusCode') !== false)
                || (stripos($e->getMessage(), 'endpoint') !== false)
            );
        }
        $this->assertTrue($thrown);
    }
}
