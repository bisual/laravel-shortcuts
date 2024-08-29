<?php

namespace Bisual\LaravelShortcuts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller as BaseController;

abstract class CrudController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public static $repository = CrudRepository::class;

    public static $model = Model::class;

    public static bool $authorize = true;

    public static $storeRequestClass = Request::class;

    public static $updateRequestClass = Request::class;

    public function index(Request $request, $functionExtraParametersTreatment = null)
    {
        if (static::$authorize) {
            $this->authorize('viewAny', static::$model);
        }
        $params = Validator::make($request->query(),ControllerValidationHelper::indexQueryParametersValidation())->validate();

        if ($functionExtraParametersTreatment != null) {
            $functionExtraParametersTreatment($params);
        }

        return JsonResource::collection((static::$repository)::index($params, isset($params['page'])));
    }

    public function show(Request $request, $id)
    {
        $item = static::$repository::show($id, $request->query());
        if (static::$authorize) {
            $this->authorize('view', $item);
        }

        return response()->json($item);
    }

    public function store(Request $request, $functionExtraParametersTreatment = null)
    {
        if (static::$authorize) {
            $this->authorize('create', static::$model);
        }

        if (static::$storeRequestClass !== 'Illuminate\Http\Request') {
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
        if (static::$authorize) {
            $this->authorize('update', $item);
        }

        if (static::$updateRequestClass !== 'Illuminate\Http\Request') {
            $data = $request->validate((new static::$updateRequestClass)->rules());
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
        if (static::$authorize) {
            $this->authorize('delete', $item);
        }

        if ($functionExtraParametersTreatment != null) {
            $functionExtraParametersTreatment($item);
        }

        return response()->json((static::$repository)::destroy($item));
    }
}
