@extends('errors.layout', ['status' => 405])

@section('title', "Méthode non autorisée")
@section('message', "Cette action n'est pas disponible de cette façon. Retournez à l'accueil et recommencez.")
