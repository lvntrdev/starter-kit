<?php

namespace Lvntr\StarterKit\Http\Controllers;

use App\Models\FileFolder;
use Illuminate\Routing\Controller;
use Lvntr\StarterKit\Domain\FileManager\Actions\AddFavoriteAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\BulkDeleteAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\CopyFileAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\CreateFolderAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\DeleteFileAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\DeleteFolderAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\DownloadFileAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\EmptyTrashAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\MoveItemAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\PermanentlyDeleteItemAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\RemoveFavoriteAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\RenameFileAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\RenameFolderAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\RestoreItemAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\UploadFileAction;
use Lvntr\StarterKit\Domain\FileManager\Queries\FavoritesContentsQuery;
use Lvntr\StarterKit\Domain\FileManager\Queries\FolderContentsQuery;
use Lvntr\StarterKit\Domain\FileManager\Queries\FolderTreeQuery;
use Lvntr\StarterKit\Domain\FileManager\Queries\TrashContentsQuery;
use Lvntr\StarterKit\Domain\FileManager\Services\FileManagerAuthorizer;
use Lvntr\StarterKit\Http\Requests\FileManager\BulkDeleteRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\CopyFileRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\DeleteFolderRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\FavoriteRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\FileManagerContextRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\MoveItemRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\RenameFileRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\StoreFolderRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\TrashItemRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\UpdateFolderRequest;
use Lvntr\StarterKit\Http\Requests\FileManager\UploadFileRequest;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FileManager HTTP surface.
 *
 * AUTHORIZATION — read before adding an action.
 * These routes are deliberately excluded from the `CheckResourcePermission`
 * middleware (see the `$routesWithoutPermissionMiddleware` list in
 * `routes/web.php`), because the required permission depends on the resolved
 * FileManager context, not on the route name. That makes
 * {@see FileManagerAuthorizer} the ONLY gate in front of every operation
 * below: an action that forgets to call it is fully unauthenticated-adjacent
 * (anyone who can reach the route can run it).
 *
 * Every action MUST therefore call exactly one of `authorizeRead()`,
 * `authorizeCreate()`, `authorizeUpdate()` or `authorizeDelete()` before it
 * touches data, and it must be the ability that matches what the action
 * really does — the abilities are not interchangeable (`files.create` does
 * not grant deletes).
 */
class FileManagerController extends Controller
{
    public function __construct(
        private readonly FileManagerAuthorizer $authorizer,
    ) {}

    public function tree(FileManagerContextRequest $request, FolderTreeQuery $query): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeRead($context);

        return to_api(['tree' => $query->execute($context)]);
    }

    public function contents(FileManagerContextRequest $request, FolderContentsQuery $query): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeRead($context);

        $folderId = $request->query('folder_id');
        $folderId = $folderId === '' ? null : $folderId;

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');

        return to_api($query->execute($context, $folderId, [
            'sort' => in_array($sort, ['name', 'size', 'date'], true) ? $sort : 'name',
            'direction' => $direction === 'desc' ? 'desc' : 'asc',
        ]));
    }

    public function bulkDelete(BulkDeleteRequest $request, BulkDeleteAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeDelete($context);

        /** @var array<int, array{type: string, id: string}> $items */
        $items = $request->input('items', []);

        $force = (bool) $request->input('force_delete', false);

        $result = $action->execute($context, $items, $force);

        $message = $force ? __('sk-file-manager.bulk_force_deleted') : __('sk-file-manager.bulk_deleted');

        return to_api($result, $message);
    }

    public function createFolder(StoreFolderRequest $request, CreateFolderAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeCreate($context);

        $folder = $action->execute(
            context: $context,
            name: $request->string('name')->toString(),
            parentId: $request->input('parent_id'),
        );

        return to_api(['folder' => [
            'id' => (string) $folder->id,
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
        ]], __('sk-file-manager.folder_created'), 201);
    }

    public function renameFolder(UpdateFolderRequest $request, FileFolder $folder, RenameFolderAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeUpdate($context);

        $folder = $action->execute($context, $folder, $request->string('name')->toString());

        return to_api(['folder' => [
            'id' => (string) $folder->id,
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
        ]], __('sk-file-manager.folder_renamed'));
    }

    public function renameFile(RenameFileRequest $request, Media $media, RenameFileAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeUpdate($context);

        $media = $action->execute($context, $media, $request->string('name')->toString());

        return to_api(['file' => [
            'id' => $media->id,
            'file_name' => $media->file_name,
            'name' => $media->name,
        ]], __('sk-file-manager.file_renamed'));
    }

    public function copyFile(CopyFileRequest $request, Media $media, CopyFileAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeCreate($context);

        $copy = $action->execute($context, $media, $request->input('target_folder_id'));

        return to_api(['file' => [
            'id' => $copy->id,
            'file_name' => $copy->file_name,
            'name' => $copy->name,
            'folder_id' => $copy->folder_id,
        ]], __('sk-file-manager.file_copied'), 201);
    }

    public function favoritesContents(FileManagerContextRequest $request, FavoritesContentsQuery $query): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeRead($context);

        return to_api($query->execute($context));
    }

    public function addFavorite(FavoriteRequest $request, AddFavoriteAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeUpdate($context);

        $action->execute(
            context: $context,
            favoritableType: $request->string('favoritable_type')->toString(),
            favoritableId: $request->string('favoritable_id')->toString(),
        );

        return to_api(null, __('sk-file-manager.favorite_added'), 201);
    }

    public function removeFavorite(FavoriteRequest $request, RemoveFavoriteAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeUpdate($context);

        $action->execute(
            context: $context,
            favoritableType: $request->string('favoritable_type')->toString(),
            favoritableId: $request->string('favoritable_id')->toString(),
        );

        return to_api(message: __('sk-file-manager.favorite_removed'));
    }

    public function trashContents(FileManagerContextRequest $request, TrashContentsQuery $query): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeRead($context);

        return to_api($query->execute($context));
    }

    public function emptyTrash(FileManagerContextRequest $request, EmptyTrashAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeDelete($context);

        $result = $action->execute($context);

        return to_api($result, __('sk-file-manager.trash_emptied'));
    }

    public function restoreItem(TrashItemRequest $request, RestoreItemAction $action): ApiResponse
    {
        $context = $request->context();
        // `update`, not `delete`: restoring from Trash destroys nothing, it
        // moves an existing item back into the tree. Requiring `files.delete`
        // here would mean a role that may not delete also may not undo a
        // delete, which is the wrong way round.
        $this->authorizer->authorizeUpdate($context);

        $action->execute(
            context: $context,
            itemType: $request->string('item_type')->toString(),
            itemId: $request->string('item_id')->toString(),
        );

        return to_api(message: __('sk-file-manager.item_restored'));
    }

    public function permanentlyDeleteItem(TrashItemRequest $request, PermanentlyDeleteItemAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeDelete($context);

        $action->execute(
            context: $context,
            itemType: $request->string('item_type')->toString(),
            itemId: $request->string('item_id')->toString(),
        );

        return to_api(message: __('sk-file-manager.item_permanently_deleted'));
    }

    public function moveItem(MoveItemRequest $request, MoveItemAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeUpdate($context);

        $action->execute(
            context: $context,
            itemType: $request->string('item_type')->toString(),
            itemId: (string) $request->input('item_id'),
            targetFolderId: $request->input('target_folder_id'),
        );

        return to_api(message: __('sk-file-manager.item_moved'));
    }

    public function deleteFolder(DeleteFolderRequest $request, FileFolder $folder, DeleteFolderAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeDelete($context);

        $action->execute($context, $folder);

        return to_api(message: __('sk-file-manager.folder_deleted'));
    }

    public function upload(UploadFileRequest $request, UploadFileAction $action): ApiResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeCreate($context);

        $uploaded = $action->execute(
            context: $context,
            files: $request->file('files') ?? [],
            folderId: $request->input('folder_id'),
            folderName: $request->input('folder_name'),
        );

        return to_api(['files' => $uploaded], __('sk-file-manager.files_uploaded'), 201);
    }

    public function deleteFile(FileManagerContextRequest $request, Media $media, DeleteFileAction $action): ApiResponse
    {
        // Through the shared request, like every other FileManager route: a
        // malformed or missing context used to reach FileManagerContextDTO
        // directly and surface as an InvalidArgumentException (500) instead of
        // the documented 422 envelope.
        $context = $request->context();
        $this->authorizer->authorizeDelete($context);

        $action->execute($context, $media);

        return to_api(message: __('sk-file-manager.file_deleted'));
    }

    public function download(FileManagerContextRequest $request, Media $media, DownloadFileAction $action): BinaryFileResponse|StreamedResponse
    {
        $context = $request->context();
        $this->authorizer->authorizeRead($context);

        return $action->execute($context, $media);
    }
}
