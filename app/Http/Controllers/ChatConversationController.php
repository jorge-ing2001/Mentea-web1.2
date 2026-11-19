<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StoreConversationRequest;
use App\Http\Requests\Chat\UpdateConversationRequest;
use App\Http\Resources\ChatConversationResource;
use App\Models\ChatConversation;
use App\Services\Chat\ConversationManager;
use App\Services\Chat\ConversationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints de lectura/gestión de conversaciones para usuarios AUTENTICADOS.
 *
 * Todas las acciones resuelven la conversación mediante
 * ConversationRepository::findForUser (scope where user_id = Auth::id()),
 * replicando el patrón de ChatReadTools. Esto neutraliza el IDOR por cambio de
 * id (reglas 1, 2, 3, 6 de §2.9) incluso frente al Gate::before global que
 * aprueba a super_admin: nunca pasamos un id crudo a la política, de modo que la
 * resolución acotada impide leer la conversación de otro usuario.
 *
 * Ante un acceso denegado se registra un evento de seguridad con
 * conversation_id + user_id + razón, SIN volcar contenido clínico (regla 10).
 */
class ChatConversationController extends Controller
{
    public function __construct(
        private ConversationRepository $repository,
        private ConversationManager $manager,
    ) {}

    /**
     * Lista solo las conversaciones del usuario autenticado (más recientes
     * primero).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = $this->repository->listForUser($request->user());

        return ChatConversationResource::collection($conversations);
    }

    /**
     * Mensajes de UNA conversación propia, en orden cronológico. Un id ajeno
     * produce 404 (sin revelar existencia) y registra el intento.
     */
    public function show(Request $request, int $conversation): ChatConversationResource
    {
        $model = $this->resolveOwned($request, $conversation, 'view');

        $model->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return new ChatConversationResource($model);
    }

    /**
     * Inicia una conversación nueva e independiente para el usuario.
     */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->manager->startNew(
            $request->user(),
            array_filter(['title' => $request->validated('title')], fn ($v) => $v !== null),
        );

        return (new ChatConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Renombra o archiva una conversación propia.
     */
    public function update(UpdateConversationRequest $request, int $conversation): ChatConversationResource
    {
        $model = $this->resolveOwned($request, $conversation, 'update');

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $model->title = $data['title'];
        }

        if (array_key_exists('status', $data)) {
            $model->status = $data['status'];
        }

        $model->save();

        return new ChatConversationResource($model);
    }

    /**
     * Elimina una conversación propia (los mensajes caen por FK cascade).
     */
    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $model = $this->resolveOwned($request, $conversation, 'delete');

        $this->repository->delete($model);

        return response()->json(['deleted' => true]);
    }

    /**
     * Resuelve la conversación SIEMPRE acotada al usuario autenticado. Si el id
     * no le pertenece, registra el intento (sin contenido) y aborta 404.
     */
    private function resolveOwned(Request $request, int $conversationId, string $ability): ChatConversation
    {
        $user = $request->user();
        $conversation = $this->repository->findForUser($user, $conversationId);

        if ($conversation === null) {
            Log::warning('Acceso denegado a una conversación de chat ajena.', [
                'conversation_id' => $conversationId,
                'user_id' => $user?->id,
                'ability' => $ability,
                'reason' => 'conversation_not_owned',
            ]);

            abort(404);
        }

        // Defensa en profundidad: aun con la resolución acotada, confirmamos la
        // habilidad vía política. No pasamos ids crudos: el modelo ya es propio.
        if (Auth::user()->cannot($ability, $conversation)) {
            Log::warning('Acceso denegado a una conversación de chat por política.', [
                'conversation_id' => $conversation->id,
                'user_id' => $user?->id,
                'ability' => $ability,
                'reason' => 'policy_denied',
            ]);

            abort(403);
        }

        return $conversation;
    }
}
