<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.5.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging;

use Cake\Core\Exception\CakeException;
use Cake\Core\InstanceConfigTrait;
use Cake\Datasource\Paging\Exception\PageOutOfBoundsException;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;

/**
 * This class is used to handle automatic model data pagination.
 */
class NumericPaginator implements PaginatorInterface
{
    use InstanceConfigTrait;

    /**
     * Default pagination settings.
     *
     * When calling paginate() these settings will be merged with the configuration
     * you provide.
     *
     * - `maxLimit` - The maximum limit users can choose to view. Defaults to 100
     * - `limit` - The initial number of items per page. Defaults to 20.
     * - `page` - The starting page, defaults to 1.
     * - `allowedParameters` - A list of parameters users are allowed to set using request
     *   parameters. Modifying this list will allow users to have more influence
     *   over pagination, be careful with what you permit.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'page' => 1,
        'limit' => 20,
        'maxLimit' => 100,
        'allowedParameters' => ['limit', 'sort', 'page', 'direction'],
    ];

    /**
     * Handles automatic pagination of model records.
     *
     * ### Configuring pagination
     *
     * When calling `paginate()` you can use the $settings parameter to pass in
     * pagination settings. These settings are used to build the queries made
     * and control other pagination settings.
     *
     * If your settings contain a key with the current table's alias. The data
     * inside that key will be used. Otherwise the top level configuration will
     * be used.
     *
     * ```
     *  $settings = [
     *    'limit' => 20,
     *    'maxLimit' => 100
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * The above settings will be used to paginate any repository. You can configure
     * repository specific settings by keying the settings with the repository alias.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'limit' => 20,
     *      'maxLimit' => 100
     *    ],
     *    'Comments' => [ ... ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * This would allow you to have different pagination settings for
     * `Articles` and `Comments` repositories.
     *
     * ### Controlling sort fields
     *
     * By default CakePHP will automatically allow sorting on any column on the
     * repository object being paginated. Often times you will want to allow
     * sorting on either associated columns or calculated fields. In these cases
     * you will need to define an allowed list of all the columns you wish to allow
     * sorting on. You can define the allowed sort fields in the `$settings` parameter:
     *
     * ```
     * $settings = [
     *   'Articles' => [
     *     'finder' => 'custom',
     *     'sortableFields' => ['title', 'author_id', 'comment_count'],
     *   ]
     * ];
     * ```
     *
     * Passing an empty array as sortableFields disallows sorting altogether.
     *
     * ### Paginating with custom finders
     *
     * You can paginate with any find type defined on your table using the
     * `finder` option.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'finder' => 'popular'
     *    ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * Would paginate using the `find('popular')` method.
     *
     * You can also pass an already created instance of a query to this method:
     *
     * ```
     * $query = $this->Articles->find('popular')->matching('Tags', function ($q) {
     *   return $q->where(['name' => 'CakePHP'])
     * });
     * $results = $paginator->paginate($query);
     * ```
     *
     * ### Scoping Request parameters
     *
     * By using request parameter scopes you can paginate multiple queries in
     * the same controller action:
     *
     * ```
     * $articles = $paginator->paginate($articlesQuery, ['scope' => 'articles']);
     * $tags = $paginator->paginate($tagsQuery, ['scope' => 'tags']);
     * ```
     *
     * Each of the above queries will use different query string parameter sets
     * for pagination data. An example URL paginating both results would be:
     *
     * ```
     * /dashboard?articles[page]=1&tags[page]=2
     * ```
     *
     * @param mixed $target The repository or query
     *   to paginate.
     * @param array $params Request params
     * @param array $settings The settings/configuration used for pagination.
     * @return \Cake\Datasource\Paging\PaginatedInterface
     * @throws \Cake\Datasource\Paging\Exception\PageOutOfBoundsException
     */
    public function paginate(
        mixed $target,
        array $params = [],
        array $settings = []
    ): PaginatedInterface {
        $query = null;
        if ($target instanceof QueryInterface) {
            $query = $target;
            $target = $query->getRepository();
            if ($target === null) {
                throw new CakeException('No repository set for query.');
            }
        }

        if (!($target instanceof RepositoryInterface)) {
            throw new CakeException('Pagination targe must be a QueryInterface or ResultSetInterface instance.');
        }

        $data = $this->extractData($target, $params, $settings);
        $query = $this->getQuery($target, $query, $data);

        $cleanQuery = clone $query;
        $results = $query->all();
        $data['count'] = count($results);
        $data['totalCount'] = $this->getCount($cleanQuery, $data);

        $pagingParams = $this->buildParams($data);
        $pagingParams['alias'] = $target->getAlias();
        if ($pagingParams['requestedPage'] > $pagingParams['currentPage']) {
            throw new PageOutOfBoundsException([
                'requestedPage' => $pagingParams['requestedPage'],
                'pagingParams' => $pagingParams,
            ]);
        }

        return new PaginatedResultSet($results, $pagingParams);
    }

    /**
     * Get query for fetching paginated results.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository instance.
     * @param \Cake\Datasource\QueryInterface|null $query Query Instance.
     * @param array<string, mixed> $data Pagination data.
     * @return \Cake\Datasource\QueryInterface
     */
    protected function getQuery(RepositoryInterface $object, ?QueryInterface $query, array $data): QueryInterface
    {
        if ($query === null) {
            $query = $object->find($data['finder'], $data['options']);
        } else {
            $query->applyOptions($data['options']);
        }

        return $query;
    }

    /**
     * Get total count of records.
     *
     * @param \Cake\Datasource\QueryInterface $query Query instance.
     * @param array $data Pagination data.
     * @return int|null
     */
    protected function getCount(QueryInterface $query, array $data): ?int
    {
        return $query->count();
    }

    /**
     * Extract pagination data needed
     *
     * @param \Cake\Datasource\RepositoryInterface $object The repository object.
     * @param array<string, mixed> $params Request params
     * @param array<string, mixed> $settings The settings/configuration used for pagination.
     * @return array Array with keys 'defaults', 'options' and 'finder'
     */
    protected function extractData(RepositoryInterface $object, array $params, array $settings): array
    {
        $alias = $object->getAlias();
        $defaults = $this->getDefaults($alias, $settings);
        $options = $this->mergeOptions($params, $defaults);
        $options = $this->validateSort($object, $options);
        $options = $this->checkLimit($options);

        $options += ['page' => 1, 'scope' => null];
        $options['page'] = (int)$options['page'] < 1 ? 1 : (int)$options['page'];
        [$finder, $options] = $this->_extractFinder($options);

        return compact('defaults', 'options', 'finder');
    }

    /**
     * Build pagination params.
     *
     * @param array<string, mixed> $data Paginator data containing keys 'options',
     *   'count', 'defaults', 'finder', 'numResults'.
     * @return array<string, mixed> Paging params.
     */
    protected function buildParams(array $data): array
    {
        $limit = $data['options']['limit'];

        $paging = $data + [
            'perPage' => $limit,
            'currentPage' => $data['options']['page'],
            'requestedPage' => $data['options']['page'],
        ];

        $paging = $this->addPageCountParams($paging, $data);
        $paging = $this->addStartEndParams($paging, $data);
        $paging = $this->addPrevNextParams($paging, $data);
        $paging = $this->addSortingParams($paging, $data);

        $paging += [
            'limit' => $data['defaults']['limit'] != $limit ? $limit : null,
            'scope' => $data['options']['scope'],
            'finder' => $data['finder'],
        ];

        return $paging;
    }

    /**
     * Add "page" and "pageCount" params.
     *
     * @param array<string, mixed> $params Paging params.
     * @param array $data Paginator data.
     * @return array<string, mixed> Updated params.
     */
    protected function addPageCountParams(array $params, array $data): array
    {
        $page = $params['currentPage'];
        $pageCount = null;

        if ($params['totalCount'] !== null) {
            $pageCount = max((int)ceil($params['totalCount'] / $params['perPage']), 1);
            $page = min($page, $pageCount);
        } elseif ($params['count'] === 0 && $params['requestedPage'] > 1) {
            $page = 1;
        }

        $params['currentPage'] = $page;
        $params['pageCount'] = $pageCount;

        return $params;
    }

    /**
     * Add "start" and "end" params.
     *
     * @param array<string, mixed> $params Paging params.
     * @param array $data Paginator data.
     * @return array<string, mixed> Updated params.
     */
    protected function addStartEndParams(array $params, array $data): array
    {
        $start = $end = 0;

        if ($params['count'] > 0) {
            $start = (($params['currentPage'] - 1) * $params['perPage']) + 1;
            $end = $start + $params['count'] - 1;
        }

        $params['startPage'] = $start;
        $params['endPage'] = $end;

        return $params;
    }

    /**
     * Add "prevPage" and "nextPage" params.
     *
     * @param array<string, mixed> $params Paginator params.
     * @param array $data Paging data.
     * @return array<string, mixed> Updated params.
     */
    protected function addPrevNextParams(array $params, array $data): array
    {
        $params['hasPrevPage'] = $params['currentPage'] > 1;
        if ($params['totalCount'] === null) {
            $params['hasNextPage'] = true;
        } else {
            $params['hasNextPage'] = $params['totalCount'] > $params['currentPage'] * $params['perPage'];
        }

        return $params;
    }

    /**
     * Add sorting / ordering params.
     *
     * @param array<string, mixed> $params Paginator params.
     * @param array $data Paging data.
     * @return array<string, mixed> Updated params.
     */
    protected function addSortingParams(array $params, array $data): array
    {
        $defaults = $data['defaults'];
        $order = (array)$data['options']['order'];
        $sortDefault = $directionDefault = false;

        if (!empty($defaults['order']) && count($defaults['order']) === 1) {
            $sortDefault = key($defaults['order']);
            $directionDefault = current($defaults['order']);
        }

        $params += [
            'sort' => $data['options']['sort'],
            'direction' => isset($data['options']['sort']) && count($order) ? current($order) : null,
            'sortDefault' => $sortDefault,
            'directionDefault' => $directionDefault,
            'completeSort' => $order,
        ];

        return $params;
    }

    /**
     * Extracts the finder name and options out of the provided pagination options.
     *
     * @param array<string, mixed> $options the pagination options.
     * @return array An array containing in the first position the finder name
     *   and in the second the options to be passed to it.
     */
    protected function _extractFinder(array $options): array
    {
        $type = !empty($options['finder']) ? $options['finder'] : 'all';
        unset($options['finder'], $options['maxLimit']);

        if (is_array($type)) {
            $options = (array)current($type) + $options;
            $type = key($type);
        }

        return [$type, $options];
    }

    /**
     * Merges the various options that Paginator uses.
     * Pulls settings together from the following places:
     *
     * - General pagination settings
     * - Model specific settings.
     * - Request parameters
     *
     * The result of this method is the aggregate of all the option sets
     * combined together. You can change config value `allowedParameters` to modify
     * which options/values can be set using request parameters.
     *
     * @param array<string, mixed> $params Request params.
     * @param array $settings The settings to merge with the request data.
     * @return array<string, mixed> Array of merged options.
     */
    public function mergeOptions(array $params, array $settings): array
    {
        if (!empty($settings['scope'])) {
            $scope = $settings['scope'];
            $params = !empty($params[$scope]) ? (array)$params[$scope] : [];
        }
        $params = array_intersect_key($params, array_flip($this->getConfig('allowedParameters')));

        return array_merge($settings, $params);
    }

    /**
     * Get the settings for a $model. If there are no settings for a specific
     * repository, the general settings will be used.
     *
     * @param string $alias Model name to get settings for.
     * @param array<string, mixed> $settings The settings which is used for combining.
     * @return array<string, mixed> An array of pagination settings for a model,
     *   or the general settings.
     */
    public function getDefaults(string $alias, array $settings): array
    {
        if (isset($settings[$alias])) {
            $settings = $settings[$alias];
        }

        $defaults = $this->getConfig();

        $maxLimit = $settings['maxLimit'] ?? $defaults['maxLimit'];
        $limit = $settings['limit'] ?? $defaults['limit'];

        if ($limit > $maxLimit) {
            $limit = $maxLimit;
        }

        $settings['maxLimit'] = $maxLimit;
        $settings['limit'] = $limit;

        return $settings + $defaults;
    }

    /**
     * Validate that the desired sorting can be performed on the $object.
     *
     * Only fields or virtualFields can be sorted on. The direction param will
     * also be sanitized. Lastly sort + direction keys will be converted into
     * the model friendly order key.
     *
     * You can use the allowedParameters option to control which columns/fields are
     * available for sorting via URL parameters. This helps prevent users from ordering large
     * result sets on un-indexed values.
     *
     * If you need to sort on associated columns or synthetic properties you
     * will need to use the `sortableFields` option.
     *
     * Any columns listed in the allowed sort fields will be implicitly trusted.
     * You can use this to sort on synthetic columns, or columns added in custom
     * find operations that may not exist in the schema.
     *
     * The default order options provided to paginate() will be merged with the user's
     * requested sorting field/direction.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array<string, mixed> $options The pagination options being used for this request.
     * @return array<string, mixed> An array of options with sort + direction removed and
     *   replaced with order if possible.
     */
    public function validateSort(RepositoryInterface $object, array $options): array
    {
        if (isset($options['sort'])) {
            $direction = null;
            if (isset($options['direction'])) {
                $direction = strtolower($options['direction']);
            }
            if (!in_array($direction, ['asc', 'desc'], true)) {
                $direction = 'asc';
            }

            $order = isset($options['order']) && is_array($options['order']) ? $options['order'] : [];
            if ($order && $options['sort'] && !str_contains($options['sort'], '.')) {
                $order = $this->_removeAliases($order, $object->getAlias());
            }

            $options['order'] = [$options['sort'] => $direction] + $order;
        } else {
            $options['sort'] = null;
        }
        unset($options['direction']);

        if (empty($options['order'])) {
            $options['order'] = [];
        }
        if (!is_array($options['order'])) {
            return $options;
        }

        $sortAllowed = false;
        if (isset($options['sortableFields'])) {
            $field = key($options['order']);
            $sortAllowed = in_array($field, $options['sortableFields'], true);
            if (!$sortAllowed) {
                $options['order'] = [];
                $options['sort'] = null;

                return $options;
            }
        }

        if (
            $options['sort'] === null
            && count($options['order']) === 1
            && !is_numeric(key($options['order']))
        ) {
            $options['sort'] = key($options['order']);
        }

        $options['order'] = $this->_prefix($object, $options['order'], $sortAllowed);

        return $options;
    }

    /**
     * Remove alias if needed.
     *
     * @param array<string, mixed> $fields Current fields
     * @param string $model Current model alias
     * @return array<string, mixed> $fields Unaliased fields where applicable
     */
    protected function _removeAliases(array $fields, string $model): array
    {
        $result = [];
        foreach ($fields as $field => $sort) {
            if (!str_contains($field, '.')) {
                $result[$field] = $sort;
                continue;
            }

            [$alias, $currentField] = explode('.', $field);

            if ($alias === $model) {
                $result[$currentField] = $sort;
                continue;
            }

            $result[$field] = $sort;
        }

        return $result;
    }

    /**
     * Prefixes the field with the table alias if possible.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array $order Order array.
     * @param bool $allowed Whether the field was allowed.
     * @return array Final order array.
     */
    protected function _prefix(RepositoryInterface $object, array $order, bool $allowed = false): array
    {
        $tableAlias = $object->getAlias();
        $tableOrder = [];
        foreach ($order as $key => $value) {
            if (is_numeric($key)) {
                $tableOrder[] = $value;
                continue;
            }
            $field = $key;
            $alias = $tableAlias;

            if (str_contains($key, '.')) {
                [$alias, $field] = explode('.', $key);
            }
            $correctAlias = ($tableAlias === $alias);

            if ($correctAlias && $allowed) {
                // Disambiguate fields in schema. As id is quite common.
                if ($object->hasField($field)) {
                    $field = $alias . '.' . $field;
                }
                $tableOrder[$field] = $value;
            } elseif ($correctAlias && $object->hasField($field)) {
                $tableOrder[$tableAlias . '.' . $field] = $value;
            } elseif (!$correctAlias && $allowed) {
                $tableOrder[$alias . '.' . $field] = $value;
            }
        }

        return $tableOrder;
    }

    /**
     * Check the limit parameter and ensure it's within the maxLimit bounds.
     *
     * @param array<string, mixed> $options An array of options with a limit key to be checked.
     * @return array<string, mixed> An array of options for pagination.
     */
    public function checkLimit(array $options): array
    {
        $options['limit'] = (int)$options['limit'];
        if ($options['limit'] < 1) {
            $options['limit'] = 1;
        }
        $options['limit'] = max(min($options['limit'], $options['maxLimit']), 1);

        return $options;
    }
}