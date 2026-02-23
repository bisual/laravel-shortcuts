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

abstract class CrudController extends BaseController
{
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

    public static array|string|FormRequest $indexQueryValidations = [];

    public static $storeRequestClass = Request::class; // pot ser un array de validacions també

    public static $updateRequestClass = Request::class; // pot ser un array de validacions també

    public function index(Request $request, $functionExtraParametersTreatment = null)
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

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($params);
        }

        return JsonResource::collection((static::$repository)::index($params, isset($params['page'])));
    }

    public function show(Request $request, $id)
    {
        $item = static::$repository::show($id, $request->query());
        if (static::$authorize['show']) {
            $this->authorize('view', $item);
        }

        return response()->json($item);
    }

    public function store(Request $request, $functionExtraParametersTreatment = null)
    {
        if (is_array(static::$storeRequestClass)) {
            $data = $request->validate(static::$storeRequestClass);
        } elseif (is_string(static::$storeRequestClass) && is_subclass_of(static::$storeRequestClass, FormRequest::class)) {
            $data = $this->handleFormRequestValidation();
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

    public function update(Request $request, $id, $functionExtraParametersTreatment = null)
    {
        $item = (static::$repository)::show($id);

        if (is_array(static::$updateRequestClass)) {
            $data = $request->validate(static::$updateRequestClass);
        } elseif (is_string(static::$updateRequestClass) && is_subclass_of(static::$updateRequestClass, FormRequest::class)) {
            $data = $this->handleFormRequestValidation();
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

    public function destroy(Request $request, $id, $functionExtraParametersTreatment = null)
    {
        $item = (static::$repository)::show($id);
        if (static::$authorize['destroy']) {
            $this->authorize('delete', $item);
        }

        if ($functionExtraParametersTreatment !== null) {
            $functionExtraParametersTreatment($item);
        }

        return response()->json((static::$repository)::destroy($item));
    }

    private function handleFormRequestValidation(): array
    {
        $formRequest = app(static::$storeRequestClass);

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
