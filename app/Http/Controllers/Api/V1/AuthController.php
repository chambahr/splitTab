<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller
{
 public function register(Request $request){$data=$request->validate(['name'=>'required|string|max:120','email'=>'required|email:rfc,dns|max:255|unique:users,email','password'=>'required|string|min:12|confirmed']);$user=User::create($data);$token=$user->createToken('splittrip-mobile')->plainTextToken;return response()->json(['user'=>$user,'token'=>$token],201);}
 public function login(Request $request){$data=$request->validate(['email'=>'required|email','password'=>'required|string']);$user=User::where('email',$data['email'])->first();if(!$user||!Hash::check($data['password'],$user->password))throw ValidationException::withMessages(['email'=>['The supplied credentials are invalid.']]);return response()->json(['user'=>$user,'token'=>$user->createToken('splittrip-mobile')->plainTextToken]);}
 public function me(Request $request){return response()->json(['user'=>$request->user()]);}
 public function logout(Request $request){$request->user()->currentAccessToken()?->delete();return response()->json(['message'=>'Signed out.']);}
}
