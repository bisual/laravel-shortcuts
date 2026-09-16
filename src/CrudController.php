<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;

/**
 * @template TModel of Model
 * @template TRepository of CrudRepository<TModel>
 */
abstract class CrudController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /** @var class-string<TRepository> */
    public static $repository = CrudRepository::class;

    /** @var class-string<TModel> */
    public static $model = Model::class;

    public static array $authorize = [
        'index' => true,
        'show' => true,
        'store' => true,
        'update' => true,
        'destroy' => true,
    ];

    public static array|string $indexQueryValidations = [];

    public static $storeRequestClass = Request::class; // pot ser un array de validacions també

    public static $updateRequestClass = Request::class; // pot ser un array de validacions també

    public function index(Request $request, ?callable $callback = null): AnonymousResourceCollection
    {
        if (static::$authorize['index']) {
            $this->authorize('viewAny', [static::$model, $request->query()]);
        }

        if (is_array(static::$indexQueryValidations) && count(static::$indexQueryValidations) > 0) {
            $params = Validator::make($request->query(), ControllerValidationHelper::indexQueryParametersValidation(static::$indexQueryValidations))->validate();
        } elseif (is_string(static::$indexQueryValidations) && is_subclass_of(static::$indexQueryValidations, FormRequest::class)) {
            $params = $this->handleQueryFormRequestValidation();
        } else {
            $params = $request->query();
        }

        if ($callback !== null) {
            $callback($params);
        }

        return JsonResource::collection((static::$repository)::index($params, isset($params['page'])));
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $item = static::$repository::show($id, $request->query());

        if (static::$authorize['show']) {
            $this->authorize('view', $item);
        }

        return response()->json($item);
    }

    public function store(Request $request, ?callable $callback = null): JsonResponse
    {
        if (is_array(static::$storeRequestClass)) {
            $data = $request->validate(static::$storeRequestClass);
        } elseif (is_string(static::$storeRequestClass) && is_subclass_of(static::$storeRequestClass, FormRequest::class)) {
            $data = $this->handleStoreFormRequestValidation();
        } else {
            $data = $request->all();
        }

        if (static::$authorize['store']) {
            $this->authorize('create', [static::$model, $data]);
        }

        if ($callback !== null) {
            $callback($data);
        }

        return response()->json((static::$repository)::store($data));
    }

    public function update(Request $request, int|string $id, ?callable $callback = null): JsonResponse
    {
        $item = (static::$repository)::show($id);

        if (is_array(static::$updateRequestClass)) {
            $data = $request->validate(static::$updateRequestClass);
        } elseif (is_string(static::$updateRequestClass) && is_subclass_of(static::$updateRequestClass, FormRequest::class)) {
            $data = $this->handleUpdateFormRequestValidation();
        } else {
            $data = $request->all();
        }

        if (static::$authorize['update']) {
            $this->authorize('update', [$item, $data]);
        }

        if ($callback !== null) {
            $callback($item, $data);
        }

        return response()->json((static::$repository)::update($item, $data));
    }

    public function destroy(Request $request, int|string $id, ?callable $callback = null): JsonResponse
    {
        $item = (static::$repository)::show($id);

        if (static::$authorize['destroy']) {
            $this->authorize('delete', $item);
        }

        if ($callback !== null) {
            $callback($item);
        }

        return response()->json((static::$repository)::destroy($item));
    }

    private function handleStoreFormRequestValidation(): array
    {
        $formRequest = app(static::$storeRequestClass);

        return $this->validateWithFormRequest($formRequest, $formRequest->all());
    }

    private function handleUpdateFormRequestValidation(): array
    {
        $formRequest = app(static::$updateRequestClass);

        return $this->validateWithFormRequest($formRequest, $formRequest->all());
    }

    private function handleQueryFormRequestValidation(): array
    {
        $formRequest = app(static::$indexQueryValidations);

        return $this->validateWithFormRequest($formRequest, $formRequest->query());
    }

    private function validateWithFormRequest(FormRequest $formRequest, array $data): array
    {
        $formRequest->merge($data);

        $formRequest->validateResolved();

        return $formRequest->validated();
    }
}
