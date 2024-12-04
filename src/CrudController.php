<?php

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
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

    public static array $indexQueryValidations = [];

    public static $storeRequestClass = Request::class; // pot ser un array de validacions també

    public static $updateRequestClass = Request::class; // pot ser un array de validacions també

    public function index(Request $request, $functionExtraParametersTreatment = null)
    {
        if (static::$authorize['index']) {
            $this->authorize('viewAny', static::$model);
        }
        $params = Validator::make($request->query(), ControllerValidationHelper::indexQueryParametersValidation(static::$indexQueryValidations))->validate();

        if ($functionExtraParametersTreatment != null) {
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
        if (static::$authorize['store']) {
            $this->authorize('create', static::$model);
        }

        if (is_array(static::$storeRequestClass)) {
            $data = $request->validate(static::$storeRequestClass);
        } elseif (static::$storeRequestClass !== 'Illuminate\Http\Request') {
            $data = $request->validate((new static::$storeRequestClass)->rules());
        } else {
            $data = $request->all();
        }

        if ($functionExtraParametersTreatment != null) {
            $functionExtraParametersTreatment($data);
        }

        return response()->json((static::$repository)::store($data));
    }

    public function update(Request $request, $id, $functionExtraParametersTreatment = null)
    {
        $item = (static::$repository)::show($id);
        if (static::$authorize['update']) {
            $this->authorize('update', $item);
        }

        if (static::$updateRequestClass !== 'Illuminate\Http\Request') {
            $data = $request->validate((new static::$updateRequestClass)->rules());
        } elseif (is_array(static::$updateRequestClass)) {
            $data = $request->validate(static::$updateRequestClass);
        } else {
            $data = $request->all();
        }

        if ($functionExtraParametersTreatment != null) {
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

        if ($functionExtraParametersTreatment != null) {
            $functionExtraParametersTreatment($item);
        }

        return response()->json((static::$repository)::destroy($item));
    }
}
