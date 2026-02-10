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

abstract class CrudController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public static string $repository = CrudRepository::class;

    public static string $model = Model::class;

    public static array $authorize = [
        'index' => true,
        'show' => true,
        'store' => true,
        'update' => true,
        'destroy' => true,
    ];

    public static array $indexQueryValidations = [];

    public static array|string|FormRequest $storeRequestClass = Request::class; // pot ser un array de validacions també

    public static array|string|FormRequest $updateRequestClass = Request::class; // pot ser un array de validacions també

    public function index(Request $request, ?callable $callback = null): AnonymousResourceCollection
    {
        if (static::$authorize['index']) {
            $this->authorize('viewAny', [static::$model, $request->query()]);
        }

        if (count(static::$indexQueryValidations) > 0) {
            $params = Validator::make($request->query(), ControllerValidationHelper::indexQueryParametersValidation(static::$indexQueryValidations))->validate();
        } else {
            $params = $request->query();
        }

        if (is_callable($callback)) {
            $callback($params);
        }

        return JsonResource::collection((static::$repository)::index($params, paginate: array_key_exists('page', $params)));
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $record = static::$repository::show($id, $request->query());

        if (static::$authorize['show']) {
            $this->authorize('view', $record);
        }

        return response()->json($record);
    }

    public function store(Request $request, ?callable $callback = null): JsonResponse
    {
        if (is_array(static::$storeRequestClass)) {
            $data = $request->validate(static::$storeRequestClass);
        } elseif (is_subclass_of(static::$storeRequestClass, FormRequest::class)) {
            $data = $this->handleFormRequestValidation($request, static::$storeRequestClass);
        } else {
            $data = $request->all();
        }

        if (static::$authorize['store']) {
            $this->authorize('create', [static::$model, $data]);
        }

        if (is_callable($callback)) {
            $callback($data);
        }

        return response()->json((static::$repository)::store($data));
    }

    public function update(Request $request, int|string $id, ?callable $callback = null): JsonResponse
    {
        $record = (static::$repository)::show($id);

        if (is_array(static::$updateRequestClass)) {
            $data = $request->validate(static::$updateRequestClass);
        } elseif (is_subclass_of(static::$updateRequestClass, FormRequest::class)) {
            $data = $this->handleFormRequestValidation($request, static::$updateRequestClass);
        } else {
            $data = $request->all();
        }

        if (static::$authorize['update']) {
            $this->authorize('update', [$record, $data]);
        }

        if (is_callable($callback)) {
            $callback($record, $data);
        }

        return response()->json((static::$repository)::update($record, $data));
    }

    public function destroy(int|string|Model $id, ?callable $callback = null): JsonResponse
    {
        $record = (static::$repository)::show($id);

        if (static::$authorize['destroy']) {
            $this->authorize('delete', $record);
        }

        if (is_callable($callback)) {
            $callback($record);
        }

        return response()->json((static::$repository)::destroy($record));
    }

    private function handleFormRequestValidation(Request $request, string $requestClass): array
    {
        /** @var FormRequest $formRequest */
        $formRequest = app($requestClass);
        $formRequest->merge($request->all());

        $formRequest->setUserResolver($request->getUserResolver());
        $formRequest->setRouteResolver($request->getRouteResolver());

        $formRequest->validateResolved();

        return $formRequest->validated();
    }
}
