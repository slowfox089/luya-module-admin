<?php

namespace luya\admin\openapi\specs;

use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use luya\admin\ngrest\base\Api;
use luya\admin\openapi\phpdoc\PhpDocParser;
use luya\admin\openapi\phpdoc\PhpDocType;
use luya\helpers\ObjectHelper;
use ReflectionClass;
use ReflectionMethod;
use Yii;
use yii\base\Action as BaseAction;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\rest\Action;
use yii\rest\IndexAction;

/**
 * Generate Specs Details.
 *
 * + works with the class php doc block
 * + works with the method php doc block
 *
 * @author Basil Suter <git@nadar.io>
 * @since 3.2.0
 */
abstract class BaseSpecs implements SpecInterface
{
    /**
     * @return ReflectionClass|ReflectionMethod
     */
    abstract public function getReflection();

    /**
     * Get the context verbname:
     * 
     * + get
     * + post
     * + delete
     * + put
     * + option
     *
     * @return string
     */
    abstract public function getVerbName();

    /**
     * @return BaseAction
     */
    abstract public function getActionObject();

    abstract public function getControllerObject();

    /**
     * @return PhpDocParser
     */
    protected function getPhpDocParser()
    {
        return new PhpDocParser($this->getReflection());
    }

    /**
     * {@inheritDoc}
     */
    public function getSummary(): string
    {
        return $this->getPhpDocParser()->getShortSummary();
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->getPhpDocParser()->getLongDescription();
    }

    /**
     * {@inheritDoc}
     */
    public function getParameters(): array
    {
        $params = [];
        if ($this->getReflection() instanceof ReflectionMethod) {
            foreach ($this->getReflection()->getParameters() as $arg) {
                $paramDoc = $this->getPhpDocParser()->getParam($arg->getName());

                $paramType = $paramDoc->getType()->getNoramlizeName();
                $params[] = new Parameter([
                    'name' => $arg->getName(),
                    'in' => 'query',
                    'required' => !$arg->isOptional(),
                    'description' => $paramDoc->getDescription(),
                    'schema' => new Schema([
                        'type' => in_array($paramType, ['integer', 'string']) ? $paramType : 'string', // only integer and string allowed
                    ])
                ]);
            }
        }

        if (ObjectHelper::isInstanceOf($this->getActionObject(), [IndexAction::class], false)) {
            // fields
            $params[] = new Parameter([
                'name' => 'fields',
                'in' => 'query',
                'required' => false,
                'description' => 'Provide a comma seperated list of fields which should be returned.',
                'example' => 'id,email,firstname,lastname',
                'schema' => new Schema(['type' => 'string']),
            ]);
            // expand
            $params[] = new Parameter([
                'name' => 'expand',
                'in' => 'query',
                'required' => false,
                'description' => 'Provide a comma seperated list of extra attributes (for example relations) which should be expand.',
                'example' => 'user,groups',
                'schema' => new Schema(['type' => 'string']),
            ]);
            // page
            $params[] = new Parameter([
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'description' => 'The page which should be resolved, page always starts as 1.',
                'example' => '1',
                'schema' => new Schema(['type' => 'integer']),
            ]);
            // per-page
            $params[] = new Parameter([
                'name' => 'per-page',
                'in' => 'query',
                'required' => false,
                'description' => 'The amount of rows to return by a page. By default its 25 rows an usually can not exceed 100 rows.',
                'example' => '100',
                'schema' => new Schema(['type' => 'integer']),
            ]);
        }


        // _language
        $params[] = new Parameter([
            'name' => '_language',
            'in' => 'query',
            'required' => false,
            'description' => 'Defines the application language to format locale specific content. The given language must be supported by the application.',
            'example' => 'en',
            'schema' => new Schema(['type' => 'string']),
        ]);
        return $params;
    }

    /**
     * {@inheritDoc}
     */
    public function getResponses(): array
    {
        $return = $this->getPhpDocParser()->getReturn();

        $response = new Response([]);
        $response->description = $return->getDescription();

        
        if ($this->getResponseContent()) {
            $response->content = $this->getResponseContent();
            $statusCode = 200;
        } else {
            $statusCode = 204;
        }

        $responseCodes = [
            $statusCode => $response,
            400 => new Response(['description' => 'Bad request.']),
            401 => new Response(['description' => 'Authentication failed.']),
            404 => new Response(['description' => 'The requested resource does not exist.']),
            405 => new Response(['description' => 'Method not allowed.']),
            500 => new Response(['description' => 'Internal server error.']),
        ];

        if ($this->getVerbName() == 'post' || $this->getVerbName() == 'put') {
            $responseCodes[422] = $this->getValidationStatusCode();
        }

        return $responseCodes;
    }

    protected function getValidationStatusCode()
    {
        return new Response([
            'description' => 'Data validation failed. Check the response body for detailed error messages.',
            'content' => [
                'application/json' => new MediaType([
                    'schema' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'field' => [
                                    'type' => 'string',
                                    'example' => 'email',
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'example' => 'Unable to find the given user, email or password is wrong.'
                                ]
                            ]
                        ]
                        
                    ],
                ])
            ]
        ]);
    }

    protected function modelContextToResponse($contextModel, $isArray = false)
    {
        $object = Yii::createObject($contextModel);

        $schema = false;

        if ($object instanceof ActiveRecord) {
            // ensure the active record table exists
            if (Yii::$app->db->getTableSchema($object::tableName(), true)) {
                $schema = new ActiveRecordToSchema($this, $object);
            }
        } elseif ($object instanceof ActiveDataProvider) {
            return [
                'application/json' => new MediaType([
                    'schema' => [
                        'type' => 'array',
                    ],
                ])
            ];
        }
        
        if (!$schema) {
            return [];
        }

        if ($this->getActionObject() instanceof IndexAction) {
            $isArray = true;
        }

        return [
            'application/json' => new MediaType([
                'schema' => $this->activeRecordToSchema($schema, $isArray),
            ])
        ];
    }

    /**
     * create an ActiveRecordSchema from a className
     *
     * @param string|array $activeRecordClassName
     * @return ActiveRecordToSchema
     */
    public function createActiveRecordSchema($activeRecordClassName)
    {
        $object = Yii::createObject($activeRecordClassName);

        if ($object instanceof ActiveRecord) {
            return new ActiveRecordToSchema($this, $object);
        }

        return false;
    }
    
    /**
     * Generate OpenAPI schema structure from ActiveRecordToSchema Object
     *
     * @param ActiveRecordToSchema $activeRecord
     * @param boolean $isArray
     * @return array
     */
    public function activeRecordToSchema(ActiveRecordToSchema $activeRecord, $isArray = false)
    {
        if ($isArray) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $activeRecord->getProperties()
                ]
            ];
        }
        return [
            'type' => 'object',
            'properties' => $activeRecord->getProperties()
        ];
    }

    protected function getNgRestApiModelClass($actionObject)
    {
        if (ObjectHelper::isInstanceOf($actionObject, [Api::class, Action::class], false)) {
            return $this->getActionObject()->modelClass;
        }

        return false;
    }

    public function createSchemaFromClass($actionObject, $asArray = false)
    {
        $class = $this->getNgRestApiModelClass($actionObject);

        if ($class) {
            $object = $this->createActiveRecordSchema($class);

            if ($object) {
                return $this->activeRecordToSchema($object, $asArray);
            }
        }

        return false;
    }

    protected function getResponseContent()
    {
        $modelClass = $this->getNgRestApiModelClass($this->getActionObject());

        if ($modelClass) {
            return $this->modelContextToResponse($modelClass);
        }

        /** @var PhpDocType $type */
        $type = $this->getPhpDocParser()->getReturn()->getType();

        if (!$type) {
            return [];
        }

        // handle php object type
        if ($type->getIsClass()) {
            return $this->modelContextToResponse($type->getClassName(), $type->getIsArray());
        }

        // handle type array
        if ($type->getIsArray()) {
            return [
                'application/json' => new MediaType([
                    'schema' => [
                        'type' => 'array',
                        'items' => [],
                    ],
                ])
            ];
        }

        if ($type->getIsScalar()) {
            return [
                'application/json' => new MediaType([
                    'schema' => [
                        'type' => $type->name,
                    ],
                ])
            ];
        }

        return [];
    }
}