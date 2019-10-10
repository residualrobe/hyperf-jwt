<?php
/**
 * Created by PhpStorm.
 * User: nizerin
 * Date: 2019-09-01
 * Time: 11:43
 */

namespace Hyperf\JwtAuth;

use Lcobucci\JWT\Token;
use Hyperf\JwtAuth\Exception\TokenValidException;
use Hyperf\JwtAuth\Exception\JWTException;
use Hyperf\JwtAuth\Traits\CommonTrait;
use Hyperf\Di\Annotation\Inject;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * https://github.com/PHP-OPEN-HUB/hyperf-jwt
 * @author NiZerin <nizerin98@gmail.com>
 */
class Jwt
{
    use CommonTrait;

    /**
     * @Inject
     * @var Blacklist
     */
    protected $blacklist;

    /**
     * 生成token
     * @param  array  $claims
     * @param  bool  $isInsertSsoBlack  是否把单点登录生成的token加入黑名单
     * @return Token
     */
    public function getToken(array $claims, $isInsertSsoBlack = true)
    {
        if ($this->loginType == 'mpop') { // 多点登录
            $uniqid = uniqid();
        } else { // 单点登录
            if (empty($claims[$this->ssoKey])) {
                throw new JWTException("There is no {$this->ssoKey} key in the claims", 500);
            }
            $uniqid = $claims[$this->ssoKey];
        }

        $signer = new $this->supportedAlgs[$this->alg];
        $time = time();

        $builder = $this->getBuilder()
            ->identifiedBy($uniqid) // 设置jwt的jti
            ->issuedAt($time)// (iat claim) 发布时间
            ->canOnlyBeUsedAfter($time)// (nbf claim) 在此之前不可用
            ->expiresAt($time + $this->ttl);// (exp claim) 到期时间

        foreach ($claims as $k => $v) {
            $builder = $builder->withClaim($k, $v); // 自定义数据
        }

        $token = $builder->getToken($signer, $this->getKey()); // Retrieves the generated token

        if ($this->loginType == 'sso' && $isInsertSsoBlack) { // 单点登录要把所有的以前生成的token都失效
            $this->blacklist->add($token);
        }

        return $token; // 返回的是token对象，使用强转换会自动转换成token字符串。Token对象采用了__toString魔术方法
    }

    /**
     * 刷新token
     * @return Token
     * @throws InvalidArgumentException
     */
    public function refreshToken()
    {
        if (!$this->getHeaderToken()) {
            throw new JWTException('A token is required', 500);
        }
        $claims = $this->blacklist->add($this->getTokenObj());
        unset($claims['iat']);
        unset($claims['nbf']);
        unset($claims['exp']);
        unset($claims['jti']);
        return $this->getToken($claims);
    }

    /**
     * 让token失效
     * @return bool
     * @throws InvalidArgumentException
     */
    public function logout()
    {
        $this->getHeaderToken();

        $this->blacklist->add($this->getTokenObj());

        return true;
    }

    /**
     * 验证token
     * @param  bool  $validate
     * @param  bool  $verify
     * @return true
     * @throws Throwable
     */
    public function checkToken($validate = true, $verify = true)
    {
        try {
            $token = $this->getTokenObj();
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }

        if ($this->enalbed) {
            $claims = $this->claimsToArray($token->getClaims());
            // 验证token是否存在黑名单
            if ($this->blacklist->has($claims)) {
                throw new TokenValidException('Token authentication does not pass', 401);
            }
        }

        if ($validate && !$this->validateToken($token)) {
            throw new TokenValidException('Token authentication does not pass', 401);
        }
        if ($verify && !$this->verifyToken($token)) {
            throw new TokenValidException('Token authentication does not pass', 401);
        }

        return true;
    }

    /**
     * 获取Token token
     * @return string|null
     */
    public function getTokenObj()
    {
        return $this->getParser()->parse($this->getHeaderToken());
    }

    /**
     * 获取token的过期剩余时间，单位为s
     * @return int|mixed
     */
    public function getTokenDynamicCacheTime()
    {
        $nowTime = time();
        $exp = $this->getTokenObj()->getClaim('exp', $nowTime);
        $expTime = $exp - $nowTime;
        return $expTime;
    }

    /**
     * 获取jwt token解析的dataç
     * @return array
     */
    public function getParserData()
    {
        $arr = [];
        $claims = $this->getTokenObj()->getClaims();
        foreach ($claims as $k => $v) {
            $arr[$k] = $v->getValue();
        }
        return $arr;
    }
}
