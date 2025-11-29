<?php

namespace App\Controllers;

abstract class ControllerBase
{
    protected $model;

    public function search($data)
    {
        try {
            if (!isset($data['searchTerm'])) {
                throw new \Exception("O termo de busca é obrigatório");
            }

            $marcas = $this->model->search($data['searchTerm'], $_REQUEST['id_conta'], $data['limit'] ?? 10, $data['offset'] ?? 0);

            http_response_code(200);
            echo json_encode($marcas);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["message" => $e->getMessage()]);
        }
    }

    abstract public function findOnly(array $data);

    abstract public function find(array $data);

    abstract public function update(array $data);

    abstract public function create(array $data);

    protected function processIncludes(&$results, $includes, $depth = 0)
    {
        if ($depth > 5) return;

        foreach ($results as $resultKey => $result) {
            foreach ($includes as $includeKey => $includeValue) {
                if ($includeValue || (is_array($includeValue) && !empty($includeValue))) {
                    foreach ($this->model->relationConfig as $relation) {
                        if ($relation['property'] === $includeKey) {
                            if (isset($relation['ignore']) && $relation['ignore']) {
                                $controller = new $relation['controller']();
                                $r = $controller->findOnly([
                                    "filter" => array_merge([$relation['column'] => $result[$relation['foreign_key']]], $includeValue['filter'] ?? []),
                                    "limit" => $includeValue['limit'] ?? null,
                                    "offset" => $includeValue['offset'] ?? null,
                                    "order" => $includeValue['order'] ?? [],
                                    "date_ranger" => $includeValue['date_ranger'] ?? [],
                                    "includes" => isset($includeValue['includes']) ? $includeValue['includes'] : []
                                ]);
                                if($r && $r[0]) {
                                    $results[$resultKey][$relation['property']] = $r;
                                } else {
                                    $results[$resultKey][$relation['property']] = [];
                                }
                                continue;
                            }

                            $model = new $relation['model']();
                            $relatedItems = $model->find(
                                array_merge([$relation['foreign_key'] => $result['id']], $includeValue['filter'] ?? []),
                                $includeValue['limit'] ?? null,
                                $includeValue['offset'] ?? null,
                                $includeValue['order'] ?? [],
                                $includeValue['date_ranger'] ?? []
                            );

                            $results[$resultKey][$relation['property']] = $relatedItems;

                            if (isset($includeValue['includes']) && !empty($relatedItems)) {
                                $this->processNestedIncludes($results[$resultKey][$relation['property']], $model, $includeValue['includes'], $depth + 1);
                            }
                        }
                    }
                }
            }
        }
    }

    protected function processNestedIncludes(&$relatedItems, $model, $nestedIncludes, $depth)
    {
        foreach ($relatedItems as $itemIndex => $item) {
            foreach ($nestedIncludes as $includeKey => $includeValue) {
                if ($includeValue || (is_array($includeValue) && !empty($includeValue))) {
                    foreach ($model->relationConfig as $relation) {
                        if ($relation['property'] === $includeKey) {
                            $controller = new $relation['controller']();
                            if (isset($relation['ignore']) && $relation['ignore']) {
                                $relatedItems[$itemIndex][$includeKey] = $controller->findOnly([
                                    "filter" => array_merge([$relation['column'] => $item[$relation['foreign_key']]], $includeValue['filter'] ?? []),
                                    "limit" => $includeValue['limit'] ?? null,
                                    "offset" => $includeValue['offset'] ?? null,
                                    "order" => $includeValue['order'] ?? [],
                                    "date_ranger" => $includeValue['date_ranger'] ?? [],
                                    "includes" => isset($includeValue['includes']) ? $includeValue['includes'] : []
                                ])[0];
                            } else {
                                $subModel = new $relation['model']();
                                $subItems = $subModel->find(
                                    array_merge([$relation['foreign_key'] => $item['id']], $includeValue['filter'] ?? []),
                                    $includeValue['limit'] ?? null,
                                    $includeValue['offset'] ?? null,
                                    $includeValue['order'] ?? [],
                                    $includeValue['date_ranger'] ?? []
                                );

                                $relatedItems[$itemIndex][$includeKey] = $subItems;

                                if (isset($includeValue['includes']) && !empty($subItems)) {
                                    $this->processNestedIncludes($relatedItems[$itemIndex][$includeKey], $subModel, $includeValue['includes'], $depth + 1);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    protected function validateRequiredFields($model, $data, $ignoredFields = [])
    {
        $structure = $model->structureTable;
        $missing = [];
        $uniques = [];

        foreach ($structure as $column) {
            $isRequired = in_array('not_null', $column['flags']);
            $isPrimaryKey = in_array('primary_key', $column['flags']);
            $isUniqueKey = in_array('unique_key', $column['flags']);
            $isTimestamp = $column['native_type'] === 'TIMESTAMP';

            if ($isPrimaryKey || $isTimestamp) continue;

            if ($isRequired && (!isset($data[$column['name']]) || $data[$column['name']] === '') && !in_array($column['name'], $ignoredFields)) {
                $missing[] = $column['name'];
            }

            if ($isUniqueKey) {
                $uniques[] = $column['name'];
            }
        }

        if (!empty($missing)) {
            throw new \Exception('Campos obrigatórios não informados: ' . implode(', ', $missing));
        }

        if (!empty($uniques)) {
            foreach ($uniques as $uniqueKey) {
                if (isset($data[$uniqueKey]) && !$model->verifyUniqueKey($uniqueKey, $data[$uniqueKey])) {
                    throw new \Exception("O valor do campo {$uniqueKey} já existe.");
                }
            }
        }

        $this->validateRelatedModels($data, $model->relationConfig);
    }

    protected function validateUpdateFields($model, $newData, $currentData, $ignoredFields = [])
    {
        $structure = $model->structureTable;
        $errors = [];
        $uniques = [];

        foreach ($structure as $column) {
            $columnName = $column['name'];
            $isPrimaryKey = in_array('primary_key', $column['flags']);
            $isUniqueKey = in_array('unique_key', $column['flags']);
            $isTimestamp = $column['native_type'] === 'TIMESTAMP';

            if ($isPrimaryKey || $isTimestamp || in_array($columnName, $ignoredFields)) {
                continue;
            }

            if (isset($newData[$columnName])) {
                $isRequired = in_array('not_null', $column['flags']);
                if ($isRequired && $newData[$columnName] === '') {
                    $errors[] = "O campo {$columnName} não pode ser vazio";
                }

                if ($isUniqueKey) {
                    $uniques[] = $columnName;
                }
            }
        }

        foreach ($uniques as $uniqueKey) {
            if (isset($newData[$uniqueKey]) && $newData[$uniqueKey] !== $currentData[$uniqueKey]) {
                if (!$model->verifyUniqueKey($uniqueKey, $newData[$uniqueKey])) {
                    $errors[] = "O valor do campo {$uniqueKey} já existe";
                }
            }
        }

        if (!empty($errors)) {
            throw new \Exception('Erros de validação: ' . implode(', ', $errors));
        }

        $this->validateRelatedModelsForUpdate($newData, $currentData, $model->relationConfig);
    }

    private function validateRelatedModelsForUpdate($newData, $currentData, $relations)
    {
        foreach ($relations as $relation) {
            $property = $relation['property'];
            $modelClass = $relation['model'];

            if (isset($newData[$property])) {
                $foreignKey = $relation['foreign_key'] ?? null;

                if (isset($newData[$property]['create']) && is_array($newData[$property]['create'])) {
                    $relatedModel = new $modelClass();
                    foreach ($newData[$property]['create'] as $item) {
                        try {
                            $this->validateRequiredFields($relatedModel, $item, [$foreignKey]);
                        } catch (\Exception $e) {
                            throw new \Exception("Erro na validação de {$property} (criação): " . $e->getMessage());
                        }
                    }
                }

                if (isset($newData[$property]['update']) && is_array($newData[$property]['update'])) {
                    $relatedModel = new $modelClass();
                    foreach ($newData[$property]['update'] as $item) {
                        if (!isset($item['id'])) {
                            throw new \Exception("ID obrigatório para atualização de {$property}");
                        }

                        $existingRelatedModel = new $modelClass($item['id']);
                        $existingItem = $existingRelatedModel->current();

                        try {
                            $this->validateUpdateFields($relatedModel, $item, $existingItem, [$foreignKey]);
                        } catch (\Exception $e) {
                            throw new \Exception("Erro na validação de {$property} (atualização): " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    private function validateRelatedModels($data, $relations)
    {
        foreach ($relations as $relation) {
            $property = $relation['property'];
            $modelClass = $relation['model'];
            $minCount = $relation['min_count'] ?? 0;

            if (isset($relation['ignore']) && $relation['ignore']) {
                continue;
            }

            if ((!isset($data[$property]) || !is_array($data[$property]))) {
                if ($minCount > 0) {
                    throw new \Exception("É necessário fornecer o campo {$property} como array");
                }
                continue;
            }

            if (count($data[$property]) < $minCount) {
                throw new \Exception("É necessário fornecer pelo menos {$minCount} " .
                    ($minCount > 1 ? rtrim($property, 's') : $property));
            }

            $relatedModel = new $modelClass();

            foreach ($data[$property] as $index => $item) {
                try {
                    $this->validateRequiredFields($relatedModel, $item, [$relation['foreign_key'] ?? []]);
                } catch (\Exception $e) {
                    throw new \Exception("Erro na validação de {$property}: " . $e->getMessage());
                }
            }
        }
    }
}
