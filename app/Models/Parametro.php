<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Parámetro de operación, editable sin desplegar.
 *
 * Los accesos van cacheados en bloque y no clave por clave. La tabla tiene un
 * puñado de filas y se lee en cada estimación de espera y en cada listado de
 * cola: una sola consulta que devuelve todo cuesta lo mismo que una y evita
 * multiplicar llamadas a la caché en un bucle.
 */
class Parametro extends Model
{
    protected $table = 'parametros';
    protected $primaryKey = 'clave';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor', 'tipo', 'grupo', 'descripcion'];

    private const CACHE_KEY = 'parametros:todos';
    private const CACHE_MINUTOS = 5;

    protected static function booted(): void
    {
        // Quien edita un parámetro tiene que ver el efecto enseguida; cinco
        // minutos es el techo para cambios hechos por fuera (una consulta SQL
        // a mano, otra instancia).
        static::saved(fn () => static::olvidarCache());
        static::deleted(fn () => static::olvidarCache());
    }

    public static function olvidarCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private static function todos(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTOS),
            function () {
                // La tabla puede no existir todavía: durante `migrate` hay un
                // momento en que el código corre y el esquema aún no está. Que
                // eso devuelva los valores por defecto en vez de reventar.
                try {
                    return static::query()->pluck('valor', 'clave')->all();
                } catch (\Throwable) {
                    return [];
                }
            },
        );
    }

    private static function crudo(string $clave): ?string
    {
        $valor = self::todos()[$clave] ?? null;

        return $valor === null ? null : (string) $valor;
    }

    public static function int(string $clave, int $porDefecto): int
    {
        $valor = self::crudo($clave);

        return $valor === null || !is_numeric($valor) ? $porDefecto : (int) $valor;
    }

    public static function bool(string $clave, bool $porDefecto): bool
    {
        $valor = self::crudo($clave);

        return $valor === null
            ? $porDefecto
            : filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $porDefecto;
    }

    public static function texto(string $clave, string $porDefecto): string
    {
        return self::crudo($clave) ?? $porDefecto;
    }

    /**
     * @return array<mixed>
     */
    public static function json(string $clave, array $porDefecto): array
    {
        $valor = self::crudo($clave);
        if ($valor === null) {
            return $porDefecto;
        }

        $decodificado = json_decode($valor, true);

        return is_array($decodificado) ? $decodificado : $porDefecto;
    }

    /**
     * Fija un valor. Crea la fila si no existía, para que un parámetro nuevo se
     * pueda introducir desde el panel sin migración.
     */
    public static function fijar(string $clave, string|int|bool $valor, string $descripcion = ''): self
    {
        $parametro = static::firstOrNew(['clave' => $clave]);

        $parametro->valor = is_bool($valor) ? ($valor ? 'true' : 'false') : (string) $valor;
        $parametro->tipo = $parametro->tipo ?: (is_bool($valor) ? 'bool' : (is_int($valor) ? 'int' : 'string'));
        $parametro->grupo = $parametro->grupo ?: 'general';
        $parametro->descripcion = $descripcion ?: ($parametro->descripcion ?: $clave);
        $parametro->save();

        return $parametro;
    }
}
