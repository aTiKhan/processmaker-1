<?php

namespace ProcessMaker\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Mockery\Exception;
use ProcessMaker\Http\Controllers\Controller;
use ProcessMaker\Http\Resources\ApiCollection;
use ProcessMaker\Http\Resources\Script as ScriptResource;
use ProcessMaker\Jobs\ExecuteScript;
use ProcessMaker\Jobs\TestScript;
use ProcessMaker\Models\Script;
use ProcessMaker\Models\User;

class ScriptController extends Controller
{
    /**
     * A whitelist of attributes that should not be
     * sanitized by our SanitizeInput middleware.
     *
     * @var array
     */
    public $doNotSanitize = [
        'code',
    ];

    /**
     * Get a list of scripts in a process.
     *
     * @param Process $process
     *
     * @return ResponseFactory|Response
     *
     *
     *     @OA\Get(
     *     path="/scripts",
     *     summary="Returns all scripts that the user has access to",
     *     operationId="getScripts",
     *     tags={"Scripts"},
     *     @OA\Parameter(ref="#/components/parameters/filter"),
     *     @OA\Parameter(ref="#/components/parameters/order_by"),
     *     @OA\Parameter(ref="#/components/parameters/order_direction"),
     *     @OA\Parameter(ref="#/components/parameters/per_page"),
     *     @OA\Parameter(ref="#/components/parameters/include"),
     *
     *     @OA\Response(
     *         response=200,
     *         description="list of scripts",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/scripts"),
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 allOf={@OA\Schema(ref="#/components/schemas/metadata")},
     *             ),
     *         ),
     *     ),
     * )
     */
    public function index(Request $request)
    {
        // Do not return results when a key is set. Those are for connectors.
        $query = Script::nonSystem()
                    ->select('scripts.*')
                    ->where('key', null)
                    ->leftJoin('script_categories as category', 'scripts.script_category_id', '=', 'category.id');

        $include = $request->input('include', '');

        if ($include) {
            $include = explode(',', $include);
            $count = array_search('categoryCount', $include);
            if ($count !== false) {
                unset($include[$count]);
                $query->withCount('category');
            }
            if ($include) {
                $query->with($include);
            }
        }

        $filter = $request->input('filter', '');
        if (!empty($filter)) {
            $filter = '%' . $filter . '%';
            $query->where(function ($query) use ($filter) {
                $query->Where('title', 'like', $filter)
                    ->orWhere('description', 'like', $filter)
                    ->orWhere('language', 'like', $filter)
                    ->orWhere('category.name', 'like', $filter);
            });
        }


        $response =
            $query->orderBy(
                $request->input('order_by', 'title'),
                $request->input('order_direction', 'ASC')
            )
            ->paginate($request->input('per_page', 10));

        return new ApiCollection($response);
    }

    /**
     * Previews executing a script, with sample data/config data
     *
     *     @OA\Post(
     *     path="/scripts/{script_id}/preview",
     *     summary="Test script code without saving it",
     *     operationId="getScriptsPreview",
     *     tags={"Scripts"},
     *         @OA\Parameter(
     *             name="script_id",
     *             in="path",
     *             @OA\Schema(type="integer"),
     *             required=true,
     *         ),
     *         @OA\Parameter(
     *             name="data",
     *             in="query",
     *             @OA\Schema(type="string"),
     *         ),
     *         @OA\Parameter(
     *             name="config",
     *             in="query",
     *             @OA\Schema(type="string"),
     *         ),
     *         @OA\Parameter(
     *             name="code",
     *             in="query",
     *             @OA\Schema(type="string"),
     *         ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="success if the script was queued",
     *         @OA\JsonContent(ref="#/components/schemas/scriptsPreview")
     *         ),
     *     ),
     * )
     */
    public function preview(Request $request, Script $script)
    {
        $data = json_decode($request->get('data'), true) ?: [];
        $config = json_decode($request->get('config'), true) ?: [];
        $code = $request->get('code');

        TestScript::dispatch($script, $request->user(), $code, $data, $config);
        return ['status' => 'success'];
    }

    /**
     * Executes a script, with sample data/config data
     *
     *     @OA\Post(
     *     path="/scripts/{script_id}/execute",
     *     summary="Execute script",
     *     operationId="executeScript",
     *     tags={"Scripts"},
     *         @OA\Parameter(
     *             name="script_id",
     *             in="path",
     *             @OA\Schema(type="integer"),
     *             required=true,
     *         ),
     *         @OA\Parameter(
     *             name="data",
     *             in="query",
     *             @OA\Schema(type="string"),
     *         ),
     *         @OA\Parameter(
     *             name="config",
     *             in="query",
     *             @OA\Schema(type="string"),
     *         ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="success if the script was queued",
     *         @OA\JsonContent(ref="#/components/schemas/scriptsPreview")
     *     ),
     * )
     */
    public function execute(Request $request, ...$scriptKey)
    {
        $script = count($scriptKey) === 1 && is_numeric($scriptKey[0]) ? Script::find($scriptKey[0]) : Script::where('key', implode('/', $scriptKey))->first();
        $data = json_decode($request->get('data'), true) ?: [];
        $config = json_decode($request->get('config'), true) ?: [];
        $watcher = $request->get('watcher', uniqid('scr', true));
        $code = $script->code;

        ExecuteScript::dispatch($script, $request->user(), $code, $data, $watcher, $config);
        return ['status' => 'success', 'key' => $watcher];
    }

    /**
     * Get the response of a script execution
     *
     *     @OA\Get(
     *     path="/scripts/execution/{key}",
     *     summary="Get the response of a script execution by execution key",
     *     operationId="getScriptExecutionResponse",
     *     tags={"Scripts"},
     *
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         @OA\Schema(type="string"),
     *         required=true,
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="response of a script execution",
     *         @OA\JsonContent(),
     *     ),
     * )
     */
    public function execution($key)
    {
        return response()->json(Cache::get("srn.$key"));
    }

    /**
     * Get a single script in a process.
     *
     * @param Script $script
     *
     * @return ResponseFactory|Response
     *
     *     @OA\Get(
     *     path="/scripts/{script_id}",
     *     summary="Get single script by ID",
     *     operationId="getScriptsById",
     *     tags={"Scripts"},
     *     @OA\Parameter(
     *         description="ID of script to return",
     *         in="path",
     *         name="script_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully found the script",
     *         @OA\JsonContent(ref="#/components/schemas/scripts")
     *     ),
     * )
     */
    public function show(Script $script)
    {
        return new ScriptResource($script);
    }

    /**
     * Create a new script in a process.
     *
     * @param Request $request
     *
     * @return ResponseFactory|Response
     *
     *     @OA\Post(
     *     path="/scripts",
     *     summary="Save a new script",
     *     operationId="createScript",
     *     tags={"Scripts"},
     *     @OA\RequestBody(
     *       required=true,
     *       @OA\JsonContent(ref="#/components/schemas/scriptsEditable")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="success",
     *         @OA\JsonContent(ref="#/components/schemas/scripts")
     *     ),
     * )
     */
    public function store(Request $request)
    {
        $request->validate(Script::rules());
        $script = new Script();
        $script->fill($request->input());

        $script->saveOrFail();
        return new ScriptResource($script);
    }

    /**
     * Update a script in a process.
     *
     * @param Process $process
     * @param Script $script
     * @param Request $request
     *
     * @return ResponseFactory|Response
     *
     *     @OA\Put(
     *     path="/scripts/{script_id}",
     *     summary="Update a script",
     *     operationId="updateScript",
     *     tags={"Scripts"},
     *     @OA\Parameter(
     *         description="ID of script to return",
     *         in="path",
     *         name="script_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\RequestBody(
     *       required=true,
     *       @OA\JsonContent(ref="#/components/schemas/scriptsEditable")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="success",
     *     ),
     * )
     */
    public function update(Script $script, Request $request)
    {
        $request->validate(Script::rules($script));

        $script->fill($request->input());

        $script->saveOrFail();

        return response($request, 204);
    }

    /**
     * duplicate a Script.
     *
     * @param Script $script
     * @param Request $request
     *
     * @return ResponseFactory|Response
     *
     *     @OA\Put(
     *     path="/scripts/{scripts_id}/duplicate",
     *     summary="duplicate a script",
     *     operationId="duplicateScreen",
     *     tags={"scripts"},
     *     @OA\Parameter(
     *         description="ID of script to return",
     *         in="path",
     *         name="scripts_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\RequestBody(
     *       required=true,
     *       @OA\JsonContent(ref="#/components/schemas/scriptsEditable")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="success",
     *         @OA\JsonContent(ref="#/components/schemas/scripts")
     *     ),
     * )
     */
    public function duplicate(Script $script, Request $request)
    {
        $request->validate(Script::rules());
        $newScript = new Script();

        $exclude = ['id', 'created_at', 'updated_at'];
        foreach ($script->getAttributes() as $attribute => $value) {
            if (!in_array($attribute, $exclude)) {
                $newScript->{$attribute} = $script->{$attribute};
            }
        }

        if ($request->has('title')) {
            $newScript->title = $request->input('title');
        }

        if ($request->has('description')) {
            $newScript->description = $request->input('description');
        }

        $newScript->saveOrFail();
        return new ScriptResource($newScript);
    }

    /**
     * Delete a script in a process.
     *
     * @param Script $script
     *
     * @return ResponseFactory|Response
     *
     *     @OA\Delete(
     *     path="/scripts/{script_id}",
     *     summary="Delete a script",
     *     operationId="deleteScript",
     *     tags={"Scripts"},
     *     @OA\Parameter(
     *         description="ID of script to return",
     *         in="path",
     *         name="script_id",
     *         required=true,
     *         @OA\Schema(
     *           type="string",
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="success",
     *     ),
     * )
     */
    public function destroy(Script $script)
    {
        $script->delete();
        return response([], 204);
    }
}
