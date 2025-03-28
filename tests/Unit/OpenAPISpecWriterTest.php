<?php

namespace Knuckles\Scribe\Tests\Unit;

use Faker\Factory;
use Illuminate\Support\Arr;
use Knuckles\Camel\Camel;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tests\BaseUnitTest;
use Knuckles\Scribe\Tests\Fixtures\ComponentsOpenApiGenerator;
use Knuckles\Scribe\Tests\Fixtures\TestOpenApiGenerator;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Writing\OpenAPISpecWriter;

/**
 * See https://swagger.io/specification/
 */
class OpenAPISpecWriterTest extends BaseUnitTest
{
    protected $config = [
        'title' => 'My Testy Testes API',
        'description' => 'All about testy testes.',
        'base_url' => 'http://api.api.dev',
    ];

    /** @test */
    public function follows_correct_spec_structure()
    {
        $endpointData1 = $this->createMockEndpointData();
        $endpointData2 = $this->createMockEndpointData();
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];

        $results = $this->generate($groups);

        $this->assertEquals(OpenAPISpecWriter::SPEC_VERSION, $results['openapi']);
        $this->assertEquals($this->config['title'], $results['info']['title']);
        $this->assertEquals($this->config['description'], $results['info']['description']);
        $this->assertNotEmpty($results['info']['version']);
        $this->assertEquals($this->config['base_url'], $results['servers'][0]['url']);
        $this->assertIsArray($results['paths']);
        $this->assertGreaterThan(0, count($results['paths']));
    }

    /** @test */
    public function adds_endpoints_correctly_as_operations_under_paths()
    {
        $endpointData1 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['GET']]);
        $endpointData2 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['POST']]);
        $endpointData3 = $this->createMockEndpointData(['uri' => 'path1/path2']);
        $groups = [$this->createGroup([$endpointData1, $endpointData2, $endpointData3])];

        $results = $this->generate($groups);

        $this->assertIsArray($results['paths']);
        $this->assertCount(2, $results['paths']);
        $this->assertCount(2, $results['paths']['/path1']);
        $this->assertCount(1, $results['paths']['/path1/path2']);
        $this->assertArrayHasKey('get', $results['paths']['/path1']);
        $this->assertArrayHasKey('post', $results['paths']['/path1']);
        $this->assertArrayHasKey(strtolower($endpointData3->httpMethods[0]), $results['paths']['/path1/path2']);

        collect([$endpointData1, $endpointData2, $endpointData3])->each(function (OutputEndpointData $endpoint) use ($groups, $results) {
            $endpointSpec = $results['paths']['/' . $endpoint->uri][strtolower($endpoint->httpMethods[0])];

            $tags = $endpointSpec['tags'];
            $containingGroup = Arr::first($groups, function ($group) use ($endpoint) {
                return Camel::doesGroupContainEndpoint($group, $endpoint);
            });
            $this->assertEquals([$containingGroup['name']], $tags);

            $this->assertEquals($endpoint->metadata->title, $endpointSpec['summary']);
            $this->assertEquals($endpoint->metadata->description, $endpointSpec['description']);
        });
    }

    /** @test */
    public function adds_authentication_details_correctly_as_security_info()
    {
        $endpointData1 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['GET'], 'metadata.authenticated' => true]);
        $endpointData2 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['POST'], 'metadata.authenticated' => false]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];
        $extraInfo = "When stuck trying to authenticate, have a coffee!";
        $config = array_merge($this->config, [
            'auth' => [
                'enabled' => true,
                'in' => 'bearer',
                'extra_info' => $extraInfo,
            ],
        ]);
        $writer = new OpenAPISpecWriter(new DocumentationConfig($config));
        $results = $writer->generateSpecContent($groups);

        $this->assertCount(1, $results['components']['securitySchemes']);
        $this->assertArrayHasKey('default', $results['components']['securitySchemes']);
        $this->assertEquals('http', $results['components']['securitySchemes']['default']['type']);
        $this->assertEquals('bearer', $results['components']['securitySchemes']['default']['scheme']);
        $this->assertEquals($extraInfo, $results['components']['securitySchemes']['default']['description']);
        $this->assertCount(1, $results['security']);
        $this->assertCount(1, $results['security'][0]);
        $this->assertArrayHasKey('default', $results['security'][0]);
        $this->assertArrayNotHasKey('security', $results['paths']['/path1']['get']);
        $this->assertArrayHasKey('security', $results['paths']['/path1']['post']);
        $this->assertCount(0, $results['paths']['/path1']['post']['security']);

        // Next try: auth with a query parameter
        $config = array_merge($this->config, [
            'auth' => [
                'enabled' => true,
                'in' => 'query',
                'name' => 'token',
                'extra_info' => $extraInfo,
            ],
        ]);
        $writer = new OpenAPISpecWriter(new DocumentationConfig($config));
        $results = $writer->generateSpecContent($groups);

        $this->assertCount(1, $results['components']['securitySchemes']);
        $this->assertArrayHasKey('default', $results['components']['securitySchemes']);
        $this->assertEquals('apiKey', $results['components']['securitySchemes']['default']['type']);
        $this->assertEquals($extraInfo, $results['components']['securitySchemes']['default']['description']);
        $this->assertEquals($config['auth']['name'], $results['components']['securitySchemes']['default']['name']);
        $this->assertEquals('query', $results['components']['securitySchemes']['default']['in']);
        $this->assertCount(1, $results['security']);
        $this->assertCount(1, $results['security'][0]);
        $this->assertArrayHasKey('default', $results['security'][0]);
        $this->assertArrayNotHasKey('security', $results['paths']['/path1']['get']);
        $this->assertArrayHasKey('security', $results['paths']['/path1']['post']);
        $this->assertCount(0, $results['paths']['/path1']['post']['security']);
    }

    /** @test */
    public function adds_url_parameters_correctly_as_parameters_on_path_item_object()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['POST'],
            'uri' => 'path1/{param}/{optionalParam?}',
            'urlParameters.param' => [
                'description' => 'Something',
                'required' => true,
                'example' => 56,
                'type' => 'integer',
                'name' => 'param',
            ],
            'urlParameters.optionalParam' => [
                'description' => 'Another',
                'required' => false,
                'example' => '69',
                'type' => 'string',
                'name' => 'optionalParam',
            ],
        ]);
        $endpointData2 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['POST']]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];

        $results = $this->generate($groups);

        $this->assertArrayNotHasKey('parameters', $results['paths']['/path1']);
        $this->assertCount(2, $results['paths']['/path1/{param}/{optionalParam}']['parameters']);
        $this->assertEquals([
            'in' => 'path',
            'required' => true,
            'name' => 'param',
            'description' => 'Something',
            'example' => 56,
            'schema' => ['type' => 'integer'],
        ], $results['paths']['/path1/{param}/{optionalParam}']['parameters'][0]);
        $this->assertEquals([
            'in' => 'path',
            'required' => true,
            'name' => 'optionalParam',
            'description' => 'Optional parameter. Another',
            'examples' => [
                'omitted' => ['summary' => 'When the value is omitted', 'value' => ''],
                'present' => [
                    'summary' => 'When the value is present', 'value' => '69'],
            ],
            'schema' => ['type' => 'string'],
        ], $results['paths']['/path1/{param}/{optionalParam}']['parameters'][1]);
    }

    /** @test */
    public function adds_headers_correctly_as_parameters_on_operation_object()
    {
        $endpointData1 = $this->createMockEndpointData(['httpMethods' => ['POST'], 'uri' => 'path1', 'headers.Extra-Header' => 'Some-example']);
        $endpointData2 = $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['GET'], 'headers' => []]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];

        $results = $this->generate($groups);

        $this->assertEquals([], $results['paths']['/path1']['get']['parameters']);
        $this->assertCount(1, $results['paths']['/path1']['post']['parameters']);
        $this->assertEquals([
            'in' => 'header',
            'name' => 'Extra-Header',
            'description' => '',
            'example' => 'Some-example',
            'schema' => ['type' => 'string'],
        ], $results['paths']['/path1']['post']['parameters'][0]);
    }

    /** @test */
    public function adds_query_parameters_correctly_as_parameters_on_operation_object()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['GET'],
            'uri' => '/path1',
            'headers' => [], // Emptying headers so it doesn't interfere with parameters object
            'queryParameters' => [
                'param' => [
                    'description' => 'A query param',
                    'required' => false,
                    'example' => 'hahoho',
                    'type' => 'string',
                    'name' => 'param',
                    'nullable' => false
                ],
            ],
        ]);
        $endpointData2 = $this->createMockEndpointData(['headers' => [], 'httpMethods' => ['POST'], 'uri' => '/path1',]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];

        $results = $this->generate($groups);

        $this->assertEquals([], $results['paths']['/path1']['post']['parameters']);
        $this->assertArrayHasKey('parameters', $results['paths']['/path1']['get']);
        $this->assertCount(1, $results['paths']['/path1']['get']['parameters']);
        $this->assertEquals([
            'in' => 'query',
            'required' => false,
            'name' => 'param',
            'description' => 'A query param',
            'example' => 'hahoho',
            'schema' => [
                'type' => 'string',
                'description' => 'A query param',
                'example' => 'hahoho',
                'nullable' => false
            ],
        ], $results['paths']['/path1']['get']['parameters'][0]);
    }

    /** @test */
    public function adds_body_parameters_correctly_as_requestBody_on_operation_object()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['POST'],
            'uri' => '/path1',
            'bodyParameters' => [
                'stringParam' => [
                    'name' => 'stringParam',
                    'description' => 'String param',
                    'required' => false,
                    'example' => 'hahoho',
                    'type' => 'string',
                    'nullable' => false,
                ],
                'integerParam' => [
                    'name' => 'integerParam',
                    'description' => 'Integer param',
                    'required' => true,
                    'example' => 99,
                    'type' => 'integer',
                    'nullable' => false,
                ],
                'booleanParam' => [
                    'name' => 'booleanParam',
                    'description' => 'Boolean param',
                    'required' => true,
                    'example' => false,
                    'type' => 'boolean',
                    'nullable' => false,
                ],
                'objectParam' => [
                    'name' => 'objectParam',
                    'description' => 'Object param',
                    'required' => false,
                    'example' => [],
                    'type' => 'object',
                    'nullable' => false,
                ],
                'objectParam.field' => [
                    'name' => 'objectParam.field',
                    'description' => 'Object param field',
                    'required' => false,
                    'example' => 119.0,
                    'type' => 'number',
                    'nullable' => false,
                ],
            ],
        ]);
        $endpointData2 = $this->createMockEndpointData(['httpMethods' => ['GET'], 'uri' => '/path1']);
        $endpointData3 = $this->createMockEndpointData([
            'httpMethods' => ['PUT'],
            'uri' => '/path2',
            'bodyParameters' => [
                'fileParam' => [
                    'name' => 'fileParam',
                    'description' => 'File param',
                    'required' => false,
                    'example' => null,
                    'type' => 'file',
                ],
                'numberArrayParam' => [
                    'name' => 'numberArrayParam',
                    'description' => 'Number array param',
                    'required' => false,
                    'example' => [186.9],
                    'type' => 'number[]',
                ],
                'objectArrayParam' => [
                    'name' => 'objectArrayParam',
                    'description' => 'Object array param',
                    'required' => false,
                    'example' => [[]],
                    'type' => 'object[]',
                ],
                'objectArrayParam[].field1' => [
                    'name' => 'objectArrayParam[].field1',
                    'description' => 'Object array param first field',
                    'required' => true,
                    'example' => ["hello"],
                    'type' => 'string[]',
                ],
                'objectArrayParam[].field2' => [
                    'name' => 'objectArrayParam[].field2',
                    'description' => '',
                    'required' => false,
                    'example' => "hi",
                    'type' => 'string',
                ],
            ],
        ]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2, $endpointData3])];

        $results = $this->generate($groups);

        $this->assertArrayNotHasKey('requestBody', $results['paths']['/path1']['get']);
        $this->assertArrayHasKey('requestBody', $results['paths']['/path1']['post']);
        $this->assertEquals([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'stringParam' => [
                                'description' => 'String param',
                                'example' => 'hahoho',
                                'type' => 'string',
                                'nullable' => false,
                            ],
                            'booleanParam' => [
                                'description' => 'Boolean param',
                                'example' => false,
                                'type' => 'boolean',
                                'nullable' => false,
                            ],
                            'integerParam' => [
                                'description' => 'Integer param',
                                'example' => 99,
                                'type' => 'integer',
                                'nullable' => false,
                            ],
                            'objectParam' => [
                                'description' => 'Object param',
                                'example' => [],
                                'type' => 'object',
                                'nullable' => false,
                                'properties' => [
                                    'field' => [
                                        'description' => 'Object param field',
                                        'example' => 119.0,
                                        'type' => 'number',
                                        'nullable' => false,
                                    ],
                                ],
                            ],
                        ],
                        'required' => [
                            'integerParam',
                            'booleanParam',
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['post']['requestBody']);
        $this->assertEquals([
            'required' => false,
            'content' => [
                'multipart/form-data' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'fileParam' => [
                                'description' => 'File param',
                                'type' => 'string',
                                'format' => 'binary',
                                'nullable' => false,
                            ],
                            'numberArrayParam' => [
                                'description' => 'Number array param',
                                'example' => [186.9],
                                'type' => 'array',
                                'items' => [
                                    'type' => 'number',
                                ],
                            ],
                            'objectArrayParam' => [
                                'description' => 'Object array param',
                                'example' => [[]],
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['field1'],
                                    'properties' => [
                                        'field1' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                            ],
                                            'description' => 'Object array param first field',
                                            'example' => ["hello"],
                                        ],
                                        'field2' => [
                                            'type' => 'string',
                                            'description' => '',
                                            'example' => "hi",
                                            'nullable' => false,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path2']['put']['requestBody']);
    }

    /** @test */
    public function adds_responses_correctly_as_responses_on_operation_object()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['POST'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 204,
                    'description' => 'Successfully updated.',
                    'content' => '{"this": "should be ignored"}',
                ],
                [
                    'status' => 201,
                    'description' => '',
                    'content' => '{"this": "shouldn\'t be ignored", "and this": "too", "also this": "too", "sub level 0": { "sub level 1 key 1": "sl0_sl1k1", "sub level 1 key 2": [ { "sub level 2 key 1": "sl0_sl1k2_sl2k1", "sub level 2 key 2": { "sub level 3 key 1": "sl0_sl1k2_sl2k2_sl3k1" } } ], "sub level 1 key 3": { "sub level 2 key 1": "sl0_sl1k3_sl2k2", "sub level 2 key 2": { "sub level 3 key 1": "sl0_sl1k3_sl2k2_sl3k1", "sub level 3 key null": null, "sub level 3 key integer": 99 }, "sub level 2 key 3 required" : "sl0_sl1k3_sl2k3" } } }',
                ],
            ],
            'responseFields' => [
                'and this' => [
                    'name' => 'and this',
                    'type' => 'string',
                    'description' => 'Parameter description, ha!',
                ],
                'also this' => [
                    'name' => 'also this',
                    'type' => 'string',
                    'description' => 'This response parameter is required.',
                    'required' => true,
                ],
                'sub level 0.sub level 1 key 3.sub level 2 key 1' => [
                    'description' => 'This is a description of a nested object',
                ],
                'sub level 0.sub level 1 key 3.sub level 2 key 3 required' => [
                    'description' => 'This is a description of a required nested object',
                    'required' => true,
                ],
            ],
        ]);
        $endpointData2 = $this->createMockEndpointData([
            'httpMethods' => ['PUT'],
            'uri' => '/path2',
            'responses' => [
                [
                    'status' => 200,
                    'description' => '',
                    'content' => '<<binary>> The cropped image',
                ],
            ],
        ]);
        $groups = [$this->createGroup([$endpointData1, $endpointData2])];

        $results = $this->generate($groups);

        $this->assertCount(2, $results['paths']['/path1']['post']['responses']);
        $this->assertArraySubset([
            '204' => [
                'description' => 'Successfully updated.',
            ],
            '201' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'this' => [
                                    'example' => "shouldn't be ignored",
                                    'type' => 'string',
                                ],
                                'and this' => [
                                    'description' => 'Parameter description, ha!',
                                    'example' => "too",
                                    'type' => 'string',
                                ],
                                'also this' => [
                                    'description' => 'This response parameter is required.',
                                    'example' => "too",
                                    'type' => 'string',
                                ],
                                'sub level 0' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sub level 1 key 1' => [
                                            'type' => 'string',
                                            'example' => 'sl0_sl1k1'
                                        ],
                                        'sub level 1 key 2' => [
                                            'type' => 'array',
                                            'example' => [
                                                [
                                                    'sub level 2 key 1' => 'sl0_sl1k2_sl2k1',
                                                    'sub level 2 key 2' => [
                                                        'sub level 3 key 1' => 'sl0_sl1k2_sl2k2_sl3k1'
                                                    ]
                                                ]
                                            ],
                                            'items' => [
                                                'type' => 'object'
                                            ]
                                        ],
                                        'sub level 1 key 3' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'sub level 2 key 1' => [
                                                    'type' => 'string',
                                                    'example' => 'sl0_sl1k3_sl2k2',
                                                    'description' => 'This is a description of a nested object'
                                                ],
                                                'sub level 2 key 2' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'sub level 3 key 1' => [
                                                            'type' => 'string',
                                                            'example' => 'sl0_sl1k3_sl2k2_sl3k1'
                                                        ],
                                                        'sub level 3 key null' => [
                                                            'type' => 'string',
                                                            'example' => null
                                                        ],
                                                        'sub level 3 key integer' => [
                                                            'type' => 'integer',
                                                            'example' => 99
                                                        ]
                                                    ]
                                                ],
                                                'sub level 2 key 3 required' => [
                                                    'type' => 'string',
                                                    'example' => 'sl0_sl1k3_sl2k3',
                                                    'description' => 'This is a description of a required nested object'
                                                ],

                                            ],
                                            'required' => [
                                                'sub level 2 key 3 required'
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'required' => [
                                'also this'
                            ]
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['post']['responses']);
        $this->assertCount(1, $results['paths']['/path2']['put']['responses']);
        $this->assertEquals([
            '200' => [
                'description' => 'The cropped image',
                'content' => [
                    'application/octet-stream' => [
                        'schema' => [
                            'type' => 'string',
                            'format' => 'binary',
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path2']['put']['responses']);
    }

    /** @test */
    public function adds_required_fields_on_array_of_objects()
    {
        $endpointData = $this->createMockEndpointData([
            'httpMethods' => ['GEt'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 200,
                    'description' => 'List of entities',
                    'content' => '{"data":[{"name":"Resource name","uuid":"UUID","primary":true}]}',
                ],
            ],
            'responseFields' => [
                'data' => [
                    'name' => 'data',
                    'type' => 'array',
                    'description' => 'Data wrapper',
                ],
                'data.name' => [
                    'name' => 'Resource name',
                    'type' => 'string',
                    'description' => 'Name of the resource object',
                    'required' => true,
                ],
                'data.uuid' => [
                    'name' => 'Resource UUID',
                    'type' => 'string',
                    'description' => 'Unique ID for the resource',
                    'required' => true,
                ],
                'data.primary' => [
                    'name' => 'Is primary',
                    'type' => 'bool',
                    'description' => 'Is primary resource',
                    'required' => true,
                ],
            ],
        ]);

        $groups = [$this->createGroup([$endpointData])];

        $results = $this->generate($groups);

        $this->assertArraySubset([
            '200' => [
                'description' => 'List of entities',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'description' => 'Data wrapper',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => [
                                                'type' => 'string',
                                                'description' => 'Name of the resource object',
                                            ],
                                            'uuid' => [
                                                'type' => 'string',
                                                'description' => 'Unique ID for the resource',
                                            ],
                                            'primary' => [
                                                'type' => 'boolean',
                                                'description' => 'Is primary resource',
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'name',
                                        'uuid',
                                        'primary',
                                    ]
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['get']['responses']);
    }

    /** @test */
    public function generates_correctly_for_array_of_strings()
    {
        $endpointData = $this->createMockEndpointData([
            'httpMethods' => ['GET'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 200,
                    'description' => 'List of entities',
                    'content' => '{"data":["Resource name"]}',
                ],
            ],
            'responseFields' => [
                'data' => [
                    'name' => 'data',
                    'type' => 'string[]',
                    'description' => 'Data wrapper',
                ],
            ],
        ]);

        $groups = [$this->createGroup([$endpointData])];

        $results = $this->generate($groups);

        $this->assertArraySubset([
            '200' => [
                'description' => 'List of entities',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'description' => 'Data wrapper',
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['get']['responses']);
    }

    /** @test */
    public function adds_multiple_responses_correctly_using_oneOf()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['POST'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 201,
                    'description' => 'This one',
                    'content' => '{"this": "one"}',
                ],
                [
                    'status' => 201,
                    'description' => 'No, that one.',
                    'content' => '{"that": "one"}',
                ],
                [
                    'status' => 200,
                    'description' => 'A separate one',
                    'content' => '{"the other": "one"}',
                ],
            ],
        ]);
        $groups = [$this->createGroup([$endpointData1])];

        $results = $this->generate($groups);

        $this->assertArraySubset([
            '200' => [
                'description' => 'A separate one',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'the other' => [
                                    'example' => "one",
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '201' => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'oneOf' => [
                                [
                                    'type' => 'object',
                                    'description' => 'This one',
                                    'properties' => [
                                        'this' => [
                                            'example' => "one",
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'description' => 'No, that one.',
                                    'properties' => [
                                        'that' => [
                                            'example' => "one",
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['post']['responses']);
    }

    /** @test */
    public function adds_more_than_two_answers_correctly_using_oneOf()
    {
        $endpointData1 = $this->createMockEndpointData([
            'httpMethods' => ['POST'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 201,
                    'description' => 'This one',
                    'content' => '{"this": "one"}',
                ],
                [
                    'status' => 201,
                    'description' => 'No, that one.',
                    'content' => '{"that": "one"}',
                ],
                [
                    'status' => 201,
                    'description' => 'No, another one.',
                    'content' => '{"another": "one"}',
                ],
                [
                    'status' => 200,
                    'description' => 'A separate one',
                    'content' => '{"the other": "one"}',
                ],
            ],
        ]);
        $groups = [$this->createGroup([$endpointData1])];

        $results = $this->generate($groups);

        $this->assertArraySubset([
            '200' => [
                'description' => 'A separate one',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'the other' => [
                                    'example' => "one",
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '201' => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'oneOf' => [
                                [
                                    'type' => 'object',
                                    'description' => 'This one',
                                    'properties' => [
                                        'this' => [
                                            'example' => "one",
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'description' => 'No, that one.',
                                    'properties' => [
                                        'that' => [
                                            'example' => "one",
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'description' => 'No, another one.',
                                    'properties' => [
                                        'another' => [
                                            'example' => "one",
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['post']['responses']);
    }

    /** @test */
    public function adds_enum_values_to_response_properties()
    {
        $endpointData = $this->createMockEndpointData([
            'httpMethods' => ['GEt'],
            'uri' => '/path1',
            'responses' => [
                [
                    'status' => 200,
                    'description' => 'List of entities',
                    'content' => '{"data":[{"name":"Resource name","uuid":"UUID","primary":true}]}',
                ],
            ],
            'responseFields' => [
                'data' => [
                    'name' => 'data',
                    'type' => 'array',
                    'description' => 'Data wrapper',
                ],
                'data.name' => [
                    'name' => 'Resource name',
                    'type' => 'string',
                    'description' => 'Name of the resource object',
                    'required' => true,
                ],
                'data.uuid' => [
                    'name' => 'Resource UUID',
                    'type' => 'string',
                    'description' => 'Unique ID for the resource',
                    'required' => true,
                ],
                'data.primary' => [
                    'name' => 'Is primary',
                    'type' => 'bool',
                    'description' => 'Is primary resource',
                    'required' => true,
                ],
            ],
        ]);

        $groups = [$this->createGroup([$endpointData])];

        $results = $this->generate($groups);

        $this->assertArraySubset([
            '200' => [
                'description' => 'List of entities',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'description' => 'Data wrapper',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => [
                                                'type' => 'string',
                                                'description' => 'Name of the resource object',
                                            ],
                                            'uuid' => [
                                                'type' => 'string',
                                                'description' => 'Unique ID for the resource',
                                            ],
                                            'primary' => [
                                                'type' => 'boolean',
                                                'description' => 'Is primary resource',
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'name',
                                        'uuid',
                                        'primary',
                                    ]
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path1']['get']['responses']);
    }

    /** @test */
    public function lists_required_properties_in_request_body()
    {
        $endpointData = $this->createMockEndpointData([
            'uri' => '/path',
            'httpMethods' => ['POST'],
            'bodyParameters' => [
                'my_field' => [
                    'name' => 'my_field',
                    'description' => '',
                    'required' => true,
                    'example' => 'abc',
                    'type' => 'string',
                    'nullable' => false,
                ],
                'other_field.nested_field' => [
                    'name' => 'nested_field',
                    'description' => '',
                    'required' => true,
                    'example' => 'abc',
                    'type' => 'string',
                    'nullable' => false,
                ],
            ],
        ]);
        $groups = [$this->createGroup([$endpointData])];
        $results = $this->generate($groups);

        $this->assertArraySubset([
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'my_field' => [
                                    'type' => 'string',
                                ],
                                'other_field' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'nested_field' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => ['nested_field'],
                                ],
                            ],
                            'required' => ['my_field']
                        ],
                    ],
                ],
            ],
        ], $results['paths']['/path']['post']);
    }

    /** @test */
    public function can_extend_openapi_generator()
    {
        $endpointData1 = $this->createMockEndpointData([
            'uri' => '/path',
            'httpMethods' => ['POST'],
            'custom' => ['permissions' => ['post:view']]
        ]);
        $groups = [$this->createGroup([$endpointData1])];
        $extraGenerator = TestOpenApiGenerator::class;
        $config = array_merge($this->config, [
            'openapi' => [
                'generators' => [
                    $extraGenerator,
                ],
            ],
        ]);
        $writer = new OpenAPISpecWriter(new DocumentationConfig($config));

        $results = $writer->generateSpecContent($groups);

        $this->assertEquals([['default' => ['post:view']]], $results['paths']['/path']['post']['security']);
    }

    /** @test */
    public function can_extend_openapi_generator_parameters()
    {
        $endpointData1 = $this->createMockEndpointData([
            'uri' => '/{slug}/path',
            'httpMethods' => ['POST'],
            'custom' => ['permissions' => ['post:view']],
            'urlParameters.slug' => [
                'description' => 'Something',
                'required' => true,
                'example' => 56,
                'type' => 'integer',
                'name' => 'slug',
            ],
        ]);
        $groups = [$this->createGroup([$endpointData1])];
        $extraGenerator = ComponentsOpenApiGenerator::class;
        $config = array_merge($this->config, [
            'openapi' => [
                'generators' => [
                    $extraGenerator,
                ],
            ],
        ]);
        $writer = new OpenAPISpecWriter(new DocumentationConfig($config));

        $results = $writer->generateSpecContent($groups);

        $actualParameters = $results['paths']['/{slug}/path']['parameters'];
        $this->assertCount(1, $actualParameters);
        $this->assertEquals(['$ref' =>  "#/components/parameters/slugParam"], $actualParameters[0]);
        $this->assertEquals([
            'slugParam' => [
                'in' => 'path',
                'name' => 'slug',
                'description' => 'The slug of the organization.',
                'example' => 'acme-corp',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                ],
            ]
        ], $results['components']['parameters']);
    }

    protected function createMockEndpointData(array $custom = []): OutputEndpointData
    {
        $faker = Factory::create();
        $path = '/' . $faker->word();
        $data = [
            'uri' => $path,
            'httpMethods' => $faker->randomElements(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 1),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'metadata' => [
                'title' => $faker->sentence(),
                'description' => $faker->randomElement([$faker->sentence(), '']),
                'authenticated' => $faker->boolean(),
            ],
            'urlParameters' => [], // Should be set by caller (along with custom path)
            'queryParameters' => [],
            'bodyParameters' => [],
            'responses' => [
                [
                    'status' => 200,
                    'content' => '{"random": "json"}',
                    'description' => 'Okayy',
                ],
            ],
            'responseFields' => [],
        ];

        foreach ($custom as $key => $value) {
            data_set($data, $key, $value);
        }

        return OutputEndpointData::create($data);
    }

    protected function createGroup(array $endpoints)
    {
        $faker = Factory::create();
        return [
            'description' => '',
            'name' => $faker->randomElement(['Endpoints', 'Group A', 'Group B']),
            'endpoints' => $endpoints,
        ];
    }

    protected function generate(array $groups): array
    {
        $writer = new OpenAPISpecWriter(new DocumentationConfig($this->config));
        return $writer->generateSpecContent($groups);
    }
}
