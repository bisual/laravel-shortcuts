<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

abstract class CrudController extends BaseController {
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public static $repository = CrudRepository::class;

    public static $model = Model::class;

    public static array $authorize = [
        'index' => true,
        'show' => true,
        'store' => true,
        'update' => true,
        'destroy' => true,
    ];

    public static array $indexQueryValidations = [];

    public static $storeRequestClass = Request::class; // pot ser un array de validacions també

    public static $updateRequestClass = Request::class; // pot ser un array de validacions també

    public function index(Request $request, $functionExtraParametersTreatment = null) {
        if (static::$authorize['index']) {
            $this->authorize('viewAny', static::$model, $request->query());
        }

        if(count(static::$indexQueryValidations) > 0) {
            $params = Validator::make($request->query(), ControllerValidationHelper::indexQueryParametersValidation(static::$indexQueryValidations))->validate();
        } else {
            $params = $request->query();
        }

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($params);
        }

        return JsonResource::collection((static::$repository)::index($params, isset($params['page'])));
    }

    public function show(Request $request, $id) {
        $item = static::$repository::show($id, $request->query());
        if (static::$authorize['show']) {
            $this->authorize('view', $item);
        }

        return response()->json($item);
    }

    public function store(Request $request, $functionExtraParametersTreatment = null) {
        if (is_array(static::$storeRequestClass)) {
            $data = $request->validate(static::$storeRequestClass);
        } elseif (static::$storeRequestClass !== Request::class) {
            $data = $this->handleFormRequestValidation($request, static::$storeRequestClass);
        } else {
            $data = $request->all();
        }

        if (static::$authorize['store']) {
            $this->authorize('create', [static::$model, $data]);
        }

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($data);
        }

        return response()->json((static::$repository)::store($data));
    }

    public function update(Request $request, $id, $functionExtraParametersTreatment = null) {
        $item = (static::$repository)::show($id);

        if (is_array(static::$updateRequestClass)) {
            $data = $request->validate(static::$updateRequestClass);
        } elseif (static::$updateRequestClass !== Request::class) {
            $data = $this->handleFormRequestValidation($request, static::$updateRequestClass);
        } else {
            $data = $request->all();
        }

        if (static::$authorize['update']) {
            $this->authorize('update', [$item, $data]);
        }

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($item, $data);
        }

        return response()->json((static::$repository)::update($item, $data));
    }

    public function destroy(Request $request, $id, $functionExtraParametersTreatment = null) {
        $item = (static::$repository)::show($id);
        if (static::$authorize['destroy']) {
            $this->authorize('delete', $item);
        }

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($item);
        }

        return response()->json((static::$repository)::destroy($item));
    }

    private function handleFormRequestValidation(Request $request, string $requestClass): array {
        if (!is_subclass_of($requestClass, FormRequest::class)) {
            throw new InvalidArgumentException("Class {$requestClass} must be an instance of ".FormRequest::class);
        }

        /** @var FormRequest $formRequest */
        $formRequest = app($requestClass);
        $formRequest->merge($request->all());

        $formRequest->setUserResolver($request->getUserResolver());
        $formRequest->setRouteResolver($request->getRouteResolver());

        $formRequest->validateResolved();

        return $formRequest->validated();
    }
}
