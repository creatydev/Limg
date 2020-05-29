<?php

namespace App\Http\Controllers;

use App\User;
use App\Image;
use DiscordWebhooks\Embed;
use DiscordWebhooks\Client;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Nubs\RandomNameGenerator\Vgng;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Nubs\RandomNameGenerator\Alliteration;
use Intervention\Image\Facades\Image as InterImage;

class ImageController extends Controller
{
    public function upload(Request $request)
    {
        $rules = [
            'image' => 'required | mimes:jpeg,jpg,png,svg,gif,bmp,tiff | max:15000',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            toast('Image format must be jpeg, jpg, png, svg, gif, bmp, tiff!', 'error');

            return back();
        }

        $user = (auth()->user()) ? auth()->user() : User::findOrFail(1);

        $pageName = str_replace(' ', '-', new Vgng()).'-'.Str::random(6);
        $imageName = str_replace(' ', '-', new Alliteration()).'-'.str_replace(' ', '-',
        new Vgng()).'-'.Str::random(6);
        $newFullName = $imageName.'.'.$request->file('image')->getClientOriginalExtension();
        $request->file('image')->move(('storage/images'), $newFullName);

        $image = new Image;
        $image->pageName = $pageName;
        $image->imageName = $imageName;
        $image->extension = pathinfo($newFullName, PATHINFO_EXTENSION);
        $image->path = '/i/'.$newFullName;
        $image->user_id = $user->id;
        $image->is_public = (! $user->always_public) ? 0 || (! Auth::check() || $user->always_public) : 1;
        $image->save();

        notify()->success('You have successfully upload image!');

        return redirect()->route('image.show', ['image' => $image->pageName]);
    }

    public function api_upload(Request $request)
    {
        $file = $request->file('file');
        $upload_key = $request->key;

        if ($file == null) {
            return response()->json([
                'success' => false,
                'image' => [],
                'error' => 'Please give a file to upload.',
            ], 500);
        } elseif ($upload_key == null) {
            return response()->json([
                'success' => false,
                'image' => [],
                'error' => 'Please give a api key to validate.',
            ], 500);
        } else {
            $postData = $request->only('file');
            $file = $postData['file'];

            $fileArray = ['image' => $file];

            $rules = [
                'image' => 'mimes:jpeg,jpg,png,svg,gif,bmp,tiff | required | max:15000', // max 15000kb
            ];
            $validator = Validator::make($fileArray, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'image' => [],
                    'error' => 'File must be Image.',
                ], 500);
            } else {
                $keys = User::all()->makeVisible('api_token')->pluck('api_token')->toArray();

                if (in_array($upload_key, $keys)) {
                    $user = User::where('api_token', '=', $upload_key)->first();

                    $pageName = str_replace(' ', '-', new Vgng()).'-'.Str::random(6);
                    $imageName = str_replace(' ', '-', new Alliteration()).'-'.str_replace(' ', '-',
                    new Vgng()).'-'.Str::random(6);
                    $imageFullName = $imageName.'.'.$file->getClientOriginalExtension();
                    $file->move(('storage/images'), $imageFullName);

                    $image = new Image;
                    $image->pageName = $pageName;
                    $image->imageName = $imageName;
                    $image->extension = pathinfo($imageFullName, PATHINFO_EXTENSION);
                    $image->path = '/i/'.$imageFullName;
                    $image->user_id = $user->id;
                    $image->is_public = 0;
                    $image->save();

                    if ($user->webhook_url) {
                        $webhook = new Client($user->webhook_url);
                        $embed = new Embed();

                        $embed->title('New image uploaded!');
                        $embed->image($user->domain.$image->path);
                        $embed->author($user->username, route('user.profile', ['user' => $user]), url($user->avatar));
                        $embed->footer(config('app.url'), config('app.url').'/images/favicon/favicon-32x32.png');
                        $embed->timestamp(date("c"));
                        $embed->color('7041F6');

                        $webhook->username(config('app.name'))->avatar(config('app.url').'/images/favicon/apple-touch-icon.png')->embed($embed)->send();
                    }

                    return response()->json([
                        'success' => true,
                        'image' => [
                            'url' => $user->domain.$image->path,
                            'delete_url' => route('image.delete', ['image' => $image->pageName]),
                        ],
                        'error' => '',
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'screenshot' => [],
                        'error' => 'Invalid key!',
                    ], 500);
                }
            }
        }
    }

    public function get($image)
    {
        if (strpos($image, '.') !== false) { // Afficher la page avec l'extension
            $imageLink = Image::where('path', '/i/'.$image)->firstOrFail();

            return response()->download($imageLink->fullpath, null, [], null);
        } else { // Afficher le view image

            $user = (auth()->user()) ? auth()->user() : User::findOrFail(1);
            $pageImage = Image::where('pageName', pathinfo($image, PATHINFO_FILENAME))->firstOrFail();

            return view('image.image', [
                'user' => $user,
                'image' => $pageImage,
            ]);
        }
    }

    public function infos(Request $request, Image $image)
    {
        $user = $request->user();
        abort_unless(Auth::check() && $user->id == $image->user->id, 403);

        $rules = [
            'title'      => 'max:50',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            toast('The title must contain maximum 50 characters!', 'error');

            return back();
        }

        $image->title = $request->input('title');
        $image->is_public = $request->has('is_public');
        $image->save();

        notify()->success('You have successfully updated your image!');

        return redirect(route('image.show', ['image' => $image->pageName]));
    }

    public function delete(Request $request, Image $image)
    {
        $user = $request->user();
        abort_unless(Auth::check() && $user->id == $image->user->id, 403);

        if (File::exists($image->fullpath)) {
            File::delete($image->fullpath);
            $image->delete();
        }
        notify()->success('You have successfully delete your image!');

        return redirect()->route('home');
    }

    public function download(Image $image)
    {
        return ($image->title) ? response()->download($image->fullpath, Str::slug($image->title, '-').'.'.$image->extension) : response()->download($image->fullpath);
    }

    public function build($image, $size)
    {
        $imageLink = Image::where('path', '/i/'.$image)->firstOrFail();

        $w = InterImage::make($imageLink->fullpath)->width();
        $h = InterImage::make($imageLink->fullpath)->height();

        if ($w > $h) {
            $imageSize = InterImage::make($imageLink->fullpath)->resize($size, null, function ($constraint) {
                $constraint->aspectRatio();
            });
        } else {
            $imageSize = InterImage::make($imageLink->fullpath)->resize(null, $size, function ($constraint) {
                $constraint->aspectRatio();
            });
        }

        return $imageSize->response($imageLink->extension, '80');
    }
}
